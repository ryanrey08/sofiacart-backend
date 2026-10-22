<?php

namespace App\Services;

use App\Enums\LegalDocumentAudience;
use App\Enums\LegalDocumentStatus;
use App\Enums\LegalDocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Models\UserLegalConsent;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Versioned Terms and Conditions / Data Privacy Policy files.
 *
 * - Uploads become drafts (previewable by admins only); publishing one makes it the single public
 *   version for its audience and type and supersedes the previous one. Nothing is ever deleted.
 * - Files live on a private disk under generated names; callers only ever see document IDs.
 * - Upload, publish, replace and unpublish are written to the admin audit log here (the routes
 *   are excluded from the generic AuditAdminMutation entry).
 */
class LegalDocumentService
{
    public const PDF_MIME = 'application/pdf';

    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** Generated storage paths only: "<audience>/<type>/<uuid>.<pdf|docx>". */
    protected const STORED_PATH_PATTERN = '#^(buyer|merchant)/(terms|privacy)/[0-9a-f-]{36}\.(pdf|docx)$#';

    public function __construct(
        protected AdminAuditLogger $auditLogger,
    ) {}

    /**
     * Identifies the file from its contents (not its name or the client's MIME type).
     *
     * @return array{mime: string, extension: string}|null
     */
    public function detectFormat(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = Str::lower($file->getClientOriginalExtension());

        if ($extension === 'pdf' && $mime === self::PDF_MIME && $this->hasPdfHeader($path)) {
            return ['mime' => self::PDF_MIME, 'extension' => 'pdf'];
        }

        if ($extension === 'docx'
            && in_array($mime, [self::DOCX_MIME, 'application/zip', 'application/octet-stream'], true)
            && $this->isWordDocument($path)) {
            return ['mime' => self::DOCX_MIME, 'extension' => 'docx'];
        }

        return null;
    }

    public function current(LegalDocumentAudience $audience, LegalDocumentType $type): ?LegalDocument
    {
        return LegalDocument::query()->forSlot($audience, $type)->published()->first();
    }

    /**
     * Stores a new draft version. The currently published version (if any) stays public until the
     * draft is published.
     *
     * @param  array{version_label?: ?string, effective_date?: ?string, notes?: ?string}  $details
     */
    public function upload(
        LegalDocumentAudience $audience,
        LegalDocumentType $type,
        UploadedFile $file,
        array $details,
        User $actor,
        ?Request $request = null,
    ): LegalDocument {
        $format = $this->detectFormat($file)
            ?? throw ValidationException::withMessages(['file' => ['The file must be a valid PDF or Word (.docx) document.']]);

        $path = $this->disk()->putFileAs(
            "{$audience->value}/{$type->value}",
            $file,
            Str::uuid()->toString().'.'.$format['extension'],
        );

        if ($path === false) {
            abort(500, 'The document could not be stored.');
        }

        try {
            $document = DB::transaction(function () use ($audience, $type, $file, $details, $actor, $path, $format): LegalDocument {
                $latest = (int) LegalDocument::query()->forSlot($audience, $type)->lockForUpdate()->max('version');

                return LegalDocument::create([
                    'audience' => $audience,
                    'type' => $type,
                    'version' => $latest + 1,
                    'version_label' => $details['version_label'] ?? null,
                    'effective_date' => $details['effective_date'] ?? null,
                    'notes' => $details['notes'] ?? null,
                    'status' => LegalDocumentStatus::Draft,
                    'is_current' => null,
                    'original_filename' => $this->safeFilename($file->getClientOriginalName(), $format['extension']),
                    'file_path' => $path,
                    'mime_type' => $format['mime'],
                    'file_size' => $file->getSize(),
                    'checksum' => hash_file('sha256', $file->getRealPath()),
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $this->disk()->delete($path);

            throw $exception;
        }

        $published = $this->current($audience, $type);

        $this->auditLogger->log(
            action: 'legal_document.uploaded',
            actor: $actor,
            subject: $document,
            request: $request,
            description: "Uploaded {$this->describe($document)} as a draft.",
            metadata: [
                ...$this->auditMetadata($document),
                'replaces_published_version' => $published?->version,
            ],
        );

        return $document;
    }

    /**
     * Makes the version the public one for its audience and type, superseding the current one.
     */
    public function publish(LegalDocument $document, User $actor, ?Request $request = null): LegalDocument
    {
        [$published, $replaced] = DB::transaction(function () use ($document, $actor): array {
            $slot = LegalDocument::query()
                ->forSlot($document->audience, $document->type)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $target = $slot->get($document->id) ?? abort(404);

            if ($target->isPublished()) {
                abort(409, 'This version is already published.');
            }

            $previous = $slot->first(fn (LegalDocument $version) => $version->isPublished());

            // Clear the old flag first: the unique index allows one current row per slot.
            $previous?->forceFill([
                'status' => LegalDocumentStatus::Superseded,
                'is_current' => null,
            ])->save();

            $target->forceFill([
                'status' => LegalDocumentStatus::Published,
                'is_current' => true,
                'published_by' => $actor->id,
                'published_at' => now(),
                'unpublished_by' => null,
                'unpublished_at' => null,
            ])->save();

            return [$target, $previous];
        });

        $this->auditLogger->log(
            action: 'legal_document.published',
            actor: $actor,
            subject: $published,
            request: $request,
            description: $replaced
                ? "Published {$this->describe($published)}, replacing version {$replaced->version}."
                : "Published {$this->describe($published)}.",
            metadata: [
                ...$this->auditMetadata($published),
                'replaced_document_id' => $replaced?->id,
                'replaced_version' => $replaced?->version,
            ],
        );

        if ($replaced) {
            $this->auditLogger->log(
                action: 'legal_document.replaced',
                actor: $actor,
                subject: $replaced,
                request: $request,
                description: "Replaced {$this->describe($replaced)} with version {$published->version}.",
                metadata: [
                    ...$this->auditMetadata($replaced),
                    'replacement_document_id' => $published->id,
                    'replacement_version' => $published->version,
                ],
            );
        }

        return $published;
    }

    /**
     * Takes the published version down without a replacement; the document becomes unavailable.
     */
    public function unpublish(LegalDocument $document, User $actor, ?Request $request = null): LegalDocument
    {
        $unpublished = DB::transaction(function () use ($document, $actor): LegalDocument {
            $target = LegalDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! $target->isPublished()) {
                abort(409, 'Only the published version can be unpublished.');
            }

            $target->forceFill([
                'status' => LegalDocumentStatus::Unpublished,
                'is_current' => null,
                'unpublished_by' => $actor->id,
                'unpublished_at' => now(),
            ])->save();

            return $target;
        });

        $this->auditLogger->log(
            action: 'legal_document.unpublished',
            actor: $actor,
            subject: $unpublished,
            request: $request,
            description: "Unpublished {$this->describe($unpublished)}.",
            metadata: $this->auditMetadata($unpublished),
        );

        return $unpublished;
    }

    /**
     * Streams a stored version. Callers decide who may see which version; the path is resolved
     * from the database record only and re-checked against the generated-name pattern.
     */
    public function stream(LegalDocument $document, bool $download = false, ?string $filename = null): StreamedResponse
    {
        $path = (string) $document->file_path;

        abort_unless(
            preg_match(self::STORED_PATH_PATTERN, $path) === 1 && $this->disk()->exists($path),
            404,
            'The document file was not found.',
        );

        return $this->disk()->response($path, $filename ?? $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $download ? 'attachment' : 'inline');
    }

    /**
     * A neutral download name for public copies, e.g. "SofiaCart-Merchant-Terms-and-Conditions-v2.pdf".
     */
    public function publicFilename(LegalDocument $document): string
    {
        $title = str_replace(' ', '-', "{$document->audience->label()} {$document->type->label()}");

        return "SofiaCart-{$title}-v{$document->version}.{$document->format()}";
    }

    /**
     * Validation errors when the submitted document IDs are not the currently published Terms and
     * Privacy Policy for the audience (or when either is not published).
     *
     * @return array<string, string>
     */
    public function registrationErrors(LegalDocumentAudience $audience, mixed $termsId, mixed $privacyId): array
    {
        $errors = [];

        foreach ([[LegalDocumentType::Terms, $termsId, 'terms_document_id'], [LegalDocumentType::Privacy, $privacyId, 'privacy_document_id']] as [$type, $submitted, $field]) {
            $current = $this->current($audience, $type);

            if (! $current) {
                $errors[$field] = "Registration is temporarily unavailable because the {$type->label()} is not published. Please try again later.";
            } elseif ((string) $submitted !== (string) $current->id) {
                $errors[$field] = "The {$type->label()} has been updated. Please review the current version and try again.";
            }
        }

        return $errors;
    }

    /**
     * Records the registrant's acceptance of the Terms and acknowledgment of the Privacy Policy.
     * Must run inside the registration transaction; re-checks the versions under a shared lock so a
     * concurrent publish cannot slip a different version in after validation.
     *
     * @return list<UserLegalConsent>
     */
    public function recordRegistrationConsents(User $user, LegalDocumentAudience $audience, int $termsId, int $privacyId, ?Request $request = null): array
    {
        $documents = LegalDocument::query()
            ->whereKey([$termsId, $privacyId])
            ->published()
            ->where('audience', $audience->value)
            ->sharedLock()
            ->get()
            ->keyBy(fn (LegalDocument $document) => $document->type->value);

        if ($documents->get(LegalDocumentType::Terms->value)?->id !== $termsId
            || $documents->get(LegalDocumentType::Privacy->value)?->id !== $privacyId) {
            throw ValidationException::withMessages([
                'terms_document_id' => ['The legal documents have been updated. Please review the current versions and try again.'],
            ]);
        }

        $acceptedAt = now();

        return $documents->values()->map(fn (LegalDocument $document) => $user->legalConsents()->create([
            'legal_document_id' => $document->id,
            'audience' => $document->audience,
            'type' => $document->type,
            'version' => $document->version,
            'action' => $document->type->consentAction(),
            'context' => "{$audience->value}_registration",
            'accepted_at' => $acceptedAt,
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 509) ?: null,
        ]))->all();
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(config('legal_documents.disk'));
    }

    protected function hasPdfHeader(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 1024);
        fclose($handle);

        return str_contains($head, '%PDF-');
    }

    /**
     * A real WordprocessingML document: has the main document part and is not macro-enabled.
     */
    protected function isWordDocument(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            $contentTypes = $zip->getFromName('[Content_Types].xml');

            return is_string($contentTypes)
                && $zip->locateName('word/document.xml') !== false
                && str_contains($contentTypes, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml')
                && ! str_contains(Str::lower($contentTypes), 'macroenabled')
                && $zip->locateName('word/vbaProject.bin') === false;
        } finally {
            $zip->close();
        }
    }

    /**
     * Keeps the uploaded name for display only: no directories or control characters, bounded length.
     */
    protected function safeFilename(string $name, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F\/]+/u', '', $base));

        return Str::limit($base !== '' ? $base : 'document', 200, '').'.'.$extension;
    }

    protected function describe(LegalDocument $document): string
    {
        return "{$document->audience->label()} {$document->type->label()} version {$document->version}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditMetadata(LegalDocument $document): array
    {
        return [
            'audience' => $document->audience->value,
            'type' => $document->type->value,
            'version' => $document->version,
            'version_label' => $document->version_label,
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'checksum' => $document->checksum,
        ];
    }
}
