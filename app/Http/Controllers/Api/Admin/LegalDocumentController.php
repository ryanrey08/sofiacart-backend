<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LegalDocumentAudience;
use App\Enums\LegalDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadLegalDocumentRequest;
use App\Http\Resources\Admin\AdminLegalDocumentResource;
use App\Models\LegalDocument;
use App\Services\LegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super Admin management of the Terms and Conditions and Data Privacy Policy for buyers and
 * merchants. Mutations are audited by LegalDocumentService.
 */
class LegalDocumentController extends Controller
{
    protected const RELATIONS = ['uploader:id,name', 'publisher:id,name', 'unpublisher:id,name'];

    public function __construct(
        protected LegalDocumentService $documents,
    ) {}

    /**
     * Every audience/type slot with its published version, newest version and version count.
     */
    public function index(): JsonResponse
    {
        $versions = LegalDocument::query()
            ->with(self::RELATIONS)
            ->orderByDesc('version')
            ->get()
            ->groupBy(fn (LegalDocument $document) => $document->audience->value.'.'.$document->type->value);

        $slots = [];
        foreach (LegalDocumentAudience::cases() as $audience) {
            foreach (LegalDocumentType::cases() as $type) {
                $slotVersions = $versions->get($audience->value.'.'.$type->value, collect());
                $slots[] = [
                    'audience' => $audience->value,
                    'audience_label' => $audience->label(),
                    'type' => $type->value,
                    'title' => $type->label(),
                    'published' => ($published = $slotVersions->first(fn (LegalDocument $document) => $document->isPublished()))
                        ? AdminLegalDocumentResource::make($published) : null,
                    'latest' => ($latest = $slotVersions->first()) ? AdminLegalDocumentResource::make($latest) : null,
                    'versions_count' => $slotVersions->count(),
                    'last_updated_at' => $slotVersions->max('updated_at')?->toISOString(),
                ];
            }
        }

        return response()->json(['data' => $slots]);
    }

    /**
     * Version history of one slot, newest first.
     */
    public function versions(string $audience, string $type): JsonResponse
    {
        $versions = LegalDocument::query()
            ->forSlot(LegalDocumentAudience::from($audience), LegalDocumentType::from($type))
            ->with(self::RELATIONS)
            ->orderByDesc('version')
            ->get();

        return response()->json(['data' => AdminLegalDocumentResource::collection($versions)]);
    }

    public function store(UploadLegalDocumentRequest $request, string $audience, string $type): JsonResponse
    {
        $document = $this->documents->upload(
            LegalDocumentAudience::from($audience),
            LegalDocumentType::from($type),
            $request->file('file'),
            $request->safe()->only(['version_label', 'effective_date', 'notes']),
            $request->user(),
            $request,
        );

        return AdminLegalDocumentResource::make($document->load(self::RELATIONS))
            ->additional(['message' => 'Draft uploaded. Preview it, then publish to make it public.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(LegalDocument $legalDocument): AdminLegalDocumentResource
    {
        return AdminLegalDocumentResource::make($legalDocument->load(self::RELATIONS));
    }

    /**
     * Streams any version (including drafts) for preview inside the admin panel.
     */
    public function file(Request $request, LegalDocument $legalDocument): StreamedResponse
    {
        return $this->documents->stream($legalDocument, $request->boolean('download'));
    }

    public function publish(Request $request, LegalDocument $legalDocument): JsonResponse
    {
        $published = $this->documents->publish($legalDocument, $request->user(), $request);

        return AdminLegalDocumentResource::make($published->load(self::RELATIONS))
            ->additional(['message' => "Version {$published->version} is now published."])
            ->response();
    }

    public function unpublish(Request $request, LegalDocument $legalDocument): JsonResponse
    {
        $unpublished = $this->documents->unpublish($legalDocument, $request->user(), $request);

        return AdminLegalDocumentResource::make($unpublished->load(self::RELATIONS))
            ->additional(['message' => "Version {$unpublished->version} has been unpublished. The document is now unavailable to the public."])
            ->response();
    }
}
