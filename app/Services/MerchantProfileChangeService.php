<?php

namespace App\Services;

use App\Admin\AdminPermissionRegistry;
use App\Enums\MerchantChangeRequestStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\MerchantManagementController;
use App\Models\Merchant;
use App\Models\MerchantChangeRequest;
use App\Models\User;
use App\Notifications\MerchantProfileChangeReviewed;
use App\Notifications\MerchantProfileChangeSubmitted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Merchant profile edits go through an approval step: a merchant's submission is stored as a
 * pending MerchantChangeRequest and the live `merchants` row is only written when an admin
 * approves it. This service is the single place that applies requested values to the live row.
 *
 * Replacement logo/banner/document uploads are kept on the private `local` disk while pending and
 * are copied to the `public` disk (where registration files live) only on approval.
 */
class MerchantProfileChangeService
{
    /**
     * Fields a merchant may request to change. Identity, tax, compliance numbers, the public
     * store URL and the onboarding status stay admin-controlled and are never accepted here.
     */
    public const EDITABLE_FIELDS = [
        'business_name',
        'business_type',
        'business_category',
        'business_address',
        'city',
        'province',
        'zip_code',
        'store_name',
        'store_category',
        'store_description',
        'store_address',
        'contact_phone',
        'contact_email',
        'social_links',
        'owner_name',
        'owner_position',
        'owner_email',
        'owner_phone',
    ];

    /**
     * Fields that are part of the merchant record but may only be changed by an admin. Stored file
     * paths are listed so they can't be set directly; files are replaced by uploading a document.
     */
    public const ADMIN_CONTROLLED_FIELDS = [
        'user_id',
        'status',
        'tin',
        'store_slug',
        'business_permit_number',
        'business_permit_path',
        'store_logo_path',
        'store_banner_path',
        'owner_birth_date',
        'government_id_type',
        'government_id_number',
        'government_id_expiry_date',
        'government_id_path',
    ];

    protected const PENDING_DISK = 'local';

    protected const LIVE_DISK = 'public';

    public function __construct(
        protected AdminAuditLogger $auditLogger,
    ) {}

    /**
     * Uploadable documents and the merchant column each one replaces on approval.
     *
     * @return array<string, string>
     */
    public static function documents(): array
    {
        return MerchantManagementController::DOCUMENTS;
    }

    /**
     * Stores the merchant's edit as a pending request. Only fields whose requested value differs
     * from the approved value are kept; the merchant row itself is never modified here.
     *
     * @param  array<string, mixed>  $input  validated editable fields
     * @param  array<string, UploadedFile>  $uploads  replacement documents keyed by document name
     */
    public function submit(Merchant $merchant, User $submitter, array $input, array $uploads = [], ?Request $request = null): MerchantChangeRequest
    {
        $stored = [];

        try {
            $changeRequest = DB::transaction(function () use ($merchant, $submitter, $input, $uploads, $request, &$stored): MerchantChangeRequest {
                // Locking the merchant row serializes submissions so two tabs cannot open two requests.
                $locked = Merchant::query()->lockForUpdate()->findOrFail($merchant->id);

                if ($locked->changeRequests()->where('status', MerchantChangeRequestStatus::Pending->value)->exists()) {
                    throw ValidationException::withMessages([
                        'changes' => ['You already have profile changes awaiting admin approval. Withdraw them or wait for the review before submitting new changes.'],
                    ]);
                }

                [$changes, $original] = $this->diff($locked, $input);
                $uploads = array_intersect_key($uploads, self::documents());

                if ($changes === [] && $uploads === []) {
                    throw ValidationException::withMessages([
                        'changes' => ['No changes to submit. Edit at least one field or upload a file before submitting for approval.'],
                    ]);
                }

                $files = [];
                foreach ($uploads as $document => $upload) {
                    $path = $upload->store("merchant-change-requests/{$locked->id}", self::PENDING_DISK);
                    $stored[] = $path;
                    $files[$document] = [
                        'path' => $path,
                        'name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                        'mime' => $upload->getMimeType(),
                        'size' => $upload->getSize(),
                    ];
                    // The approved file at submission time, for the request's history.
                    $original[$document] = $locked->getAttribute(self::documents()[$document]);
                }

                $changeRequest = $locked->changeRequests()->create([
                    'status' => MerchantChangeRequestStatus::Pending,
                    'changes' => $changes,
                    'original' => $original,
                    'files' => $files ?: null,
                    'submitted_by' => $submitter->id,
                ]);

                $this->auditLogger->log(
                    'merchant.profile.change_requested',
                    $submitter,
                    $locked,
                    $request,
                    "Profile change request #{$changeRequest->id} submitted for approval.",
                    [
                        'change_request_id' => $changeRequest->id,
                        'fields' => $changeRequest->changedFields(),
                    ],
                );

                return $changeRequest;
            });
        } catch (Throwable $exception) {
            Storage::disk(self::PENDING_DISK)->delete($stored);

            throw $exception;
        }

        $this->notify($this->reviewers(), new MerchantProfileChangeSubmitted($changeRequest, $merchant->fresh()));

        return $changeRequest;
    }

    /**
     * Applies the requested values and documents to the live merchant record and marks the request
     * approved, atomically. A request that is no longer pending cannot be applied (again).
     */
    public function approve(MerchantChangeRequest $changeRequest, User $reviewer, ?Request $request = null): MerchantChangeRequest
    {
        $published = [];

        try {
            [$approved, $merchant] = DB::transaction(function () use ($changeRequest, $reviewer, $request, &$published): array {
                $locked = $this->lockPending($changeRequest);
                $merchant = Merchant::query()->lockForUpdate()->findOrFail($locked->merchant_id);

                $updates = array_intersect_key($locked->changes ?? [], array_flip(self::EDITABLE_FIELDS));

                foreach (array_intersect_key($locked->files ?? [], self::documents()) as $document => $file) {
                    $pending = Storage::disk(self::PENDING_DISK);

                    if (! $pending->exists($file['path'])) {
                        throw ValidationException::withMessages([
                            'files' => ["The uploaded {$document} file is no longer available. Ask the merchant to submit it again."],
                        ]);
                    }

                    $livePath = 'merchants/'.($merchant->store_slug ?: $merchant->id).'/'.basename($file['path']);
                    Storage::disk(self::LIVE_DISK)->put($livePath, $pending->get($file['path']));
                    $published[] = $livePath;
                    $updates[self::documents()[$document]] = $livePath;
                }

                $merchant->update($updates);

                $locked->update([
                    'status' => MerchantChangeRequestStatus::Approved,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                ]);

                $this->auditLogger->log(
                    'admin.merchants.profile_change_approved',
                    $reviewer,
                    $merchant,
                    $request,
                    "Profile change request #{$locked->id} approved.",
                    [
                        'change_request_id' => $locked->id,
                        'fields' => $locked->changedFields(),
                    ],
                );

                return [$locked, $merchant];
            });
        } catch (Throwable $exception) {
            Storage::disk(self::LIVE_DISK)->delete($published);

            throw $exception;
        }

        // The approved files now live on the public disk; the private pending copies are no longer needed.
        $this->deletePendingFiles($approved);
        $this->notifyMerchant($approved, $merchant);

        return $approved;
    }

    /**
     * Marks the request rejected without touching the live merchant record.
     */
    public function reject(MerchantChangeRequest $changeRequest, User $reviewer, string $reason, ?Request $request = null): MerchantChangeRequest
    {
        $rejected = DB::transaction(function () use ($changeRequest, $reviewer, $reason, $request): MerchantChangeRequest {
            $locked = $this->lockPending($changeRequest);

            $locked->update([
                'status' => MerchantChangeRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->auditLogger->log(
                'admin.merchants.profile_change_rejected',
                $reviewer,
                $locked->merchant,
                $request,
                "Profile change request #{$locked->id} rejected.",
                [
                    'change_request_id' => $locked->id,
                    'fields' => $locked->changedFields(),
                    'reason' => $reason,
                ],
            );

            return $locked;
        });

        $this->deletePendingFiles($rejected);
        $this->notifyMerchant($rejected, $rejected->merchant);

        return $rejected;
    }

    /**
     * Lets the merchant cancel their own pending request before it is reviewed.
     */
    public function withdraw(MerchantChangeRequest $changeRequest, User $merchantUser, ?Request $request = null): MerchantChangeRequest
    {
        $withdrawn = DB::transaction(function () use ($changeRequest, $merchantUser, $request): MerchantChangeRequest {
            $locked = $this->lockPending($changeRequest);

            $locked->update([
                'status' => MerchantChangeRequestStatus::Withdrawn,
                'withdrawn_at' => now(),
            ]);

            $this->auditLogger->log(
                'merchant.profile.change_withdrawn',
                $merchantUser,
                $locked->merchant,
                $request,
                "Profile change request #{$locked->id} withdrawn by the merchant.",
                [
                    'change_request_id' => $locked->id,
                    'fields' => $locked->changedFields(),
                ],
            );

            return $locked;
        });

        $this->deletePendingFiles($withdrawn);

        return $withdrawn;
    }

    /**
     * Field-by-field comparison for review. Text rows compare the merchant's current approved
     * value with the requested value; `changed` compares against the current value so a field is
     * never shown as changed when it already matches the live record. File rows describe the
     * current document and the uploaded replacement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function comparison(MerchantChangeRequest $changeRequest, Merchant $merchant): array
    {
        $rows = [];

        foreach ($changeRequest->changes ?? [] as $field => $requested) {
            $current = $this->normalize($field, $merchant->getAttribute($field));

            $rows[] = [
                'field' => $field,
                'kind' => 'text',
                'current' => $current,
                'original' => $changeRequest->original[$field] ?? null,
                'requested' => $requested,
                'changed' => $current !== $this->normalize($field, $requested),
            ];
        }

        foreach ($changeRequest->files ?? [] as $document => $file) {
            $currentPath = $merchant->getAttribute(self::documents()[$document] ?? '');
            $originalPath = $changeRequest->original[$document] ?? null;

            $rows[] = [
                'field' => $document,
                'kind' => 'file',
                'current' => $currentPath ? basename($currentPath) : null,
                'original' => $originalPath ? basename($originalPath) : null,
                'requested' => $file['name'],
                'changed' => true,
                'file' => ['name' => $file['name'], 'mime' => $file['mime'], 'size' => $file['size']],
            ];
        }

        return $rows;
    }

    /**
     * Streams a pending uploaded document. Only available while the request is pending; once
     * approved the file is served from the live merchant record instead.
     */
    public function pendingFileResponse(MerchantChangeRequest $changeRequest, string $document): StreamedResponse
    {
        $file = $changeRequest->files[$document] ?? null;
        $disk = Storage::disk(self::PENDING_DISK);

        abort_unless($file && $changeRequest->isPending() && $disk->exists($file['path']), 404, 'The file was not found.');

        return $disk->response($file['path'], $file['name'], [
            'Content-Type' => $file['mime'] ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Streams one of the merchant's approved (live) documents.
     */
    public function liveFileResponse(Merchant $merchant, string $document): StreamedResponse
    {
        $column = self::documents()[$document] ?? abort(404);
        $path = $merchant->getAttribute($column);
        $disk = Storage::disk(self::LIVE_DISK);

        abort_unless($path && $disk->exists($path), 404, 'The document was not found.');

        return $disk->response($path, basename($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Admins who can approve or reject merchant changes (active, with `merchants.manage`).
     *
     * @return Collection<int, User>
     */
    protected function reviewers(): Collection
    {
        return User::query()
            ->where('role', UserRole::Admin)
            ->with(['adminRoles.permissions', 'adminPermissions'])
            ->get()
            ->filter(fn (User $admin) => $admin->hasAdminPermission(AdminPermissionRegistry::MERCHANTS_MANAGE))
            ->values();
    }

    protected function notifyMerchant(MerchantChangeRequest $changeRequest, ?Merchant $merchant): void
    {
        $owner = $merchant?->user;

        if ($owner) {
            $this->notify(collect([$owner]), new MerchantProfileChangeReviewed($changeRequest, $merchant));
        }
    }

    /**
     * Notifications are sent after the decision is committed; a mail failure is reported but never
     * undoes or blocks the approval workflow.
     */
    protected function notify(Collection $recipients, object $notification): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        rescue(fn () => Notification::send($recipients, $notification));
    }

    protected function deletePendingFiles(MerchantChangeRequest $changeRequest): void
    {
        $paths = array_column($changeRequest->files ?? [], 'path');

        if ($paths !== []) {
            Storage::disk(self::PENDING_DISK)->delete($paths);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function diff(Merchant $merchant, array $input): array
    {
        $changes = [];
        $original = [];

        foreach (array_intersect_key($input, array_flip(self::EDITABLE_FIELDS)) as $field => $value) {
            $requested = $this->normalize($field, $value);
            $current = $this->normalize($field, $merchant->getAttribute($field));

            if ($requested !== $current) {
                $changes[$field] = $requested;
                $original[$field] = $current;
            }
        }

        return [$changes, $original];
    }

    protected function lockPending(MerchantChangeRequest $changeRequest): MerchantChangeRequest
    {
        $locked = MerchantChangeRequest::query()->lockForUpdate()->findOrFail($changeRequest->id);

        if (! $locked->isPending()) {
            throw ValidationException::withMessages([
                'status' => ["This change request has already been {$locked->status->value}."],
            ]);
        }

        return $locked;
    }

    /**
     * Canonical form for comparing and storing values: trimmed strings with blanks as null and
     * "\n" line endings, and social links without empty entries in a stable key order. Line endings
     * matter because multipart form submissions turn every "\n" into "\r\n", which would otherwise
     * make an unchanged multi-line address look edited.
     */
    protected function normalize(string $field, mixed $value): mixed
    {
        if ($field === 'social_links') {
            $links = array_filter(
                array_map(fn ($link) => is_string($link) ? trim($link) : $link, (array) ($value ?? [])),
                fn ($link) => $link !== null && $link !== '',
            );
            ksort($links);

            return $links === [] ? null : $links;
        }

        if ($value === null) {
            return null;
        }

        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return $value === '' ? null : $value;
    }
}
