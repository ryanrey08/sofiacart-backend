<?php

namespace App\Http\Controllers\Api;

use App\Enums\LegalDocumentAudience;
use App\Enums\LegalDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LegalDocumentResource;
use App\Services\LegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, read-only access to the currently published legal documents (used by merchant
 * registration). Drafts and unpublished versions are never reachable here.
 */
class LegalDocumentController extends Controller
{
    public function __construct(
        protected LegalDocumentService $documents,
    ) {}

    /**
     * The published Terms and Privacy Policy for an audience; null where none is published.
     */
    public function index(string $audience): JsonResponse
    {
        $audience = LegalDocumentAudience::from($audience);

        return response()->json([
            'data' => collect(LegalDocumentType::cases())->mapWithKeys(fn (LegalDocumentType $type) => [
                $type->value => ($document = $this->documents->current($audience, $type))
                    ? LegalDocumentResource::make($document)
                    : null,
            ]),
        ]);
    }

    public function show(string $audience, string $type): LegalDocumentResource
    {
        $document = $this->documents->current(LegalDocumentAudience::from($audience), LegalDocumentType::from($type))
            ?? abort(404, 'This document is temporarily unavailable.');

        return LegalDocumentResource::make($document);
    }

    public function file(Request $request, string $audience, string $type): StreamedResponse
    {
        $document = $this->documents->current(LegalDocumentAudience::from($audience), LegalDocumentType::from($type))
            ?? abort(404, 'This document is temporarily unavailable.');

        return $this->documents->stream($document, $request->boolean('download'), $this->documents->publicFilename($document));
    }
}
