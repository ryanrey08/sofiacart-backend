<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public metadata of a published legal document version (no storage path, uploader or notes).
 */
class LegalDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $parameters = ['audience' => $this->audience->value, 'type' => $this->type->value];

        return [
            'id' => $this->id,
            'audience' => $this->audience->value,
            'type' => $this->type->value,
            'title' => $this->type->label(),
            'version' => $this->version,
            'version_label' => $this->version_label,
            'effective_date' => $this->effective_date?->toDateString(),
            'published_at' => $this->published_at?->toISOString(),
            'format' => $this->resource->format(),
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'file_url' => route('legal-documents.file', $parameters),
            'download_url' => route('legal-documents.file', [...$parameters, 'download' => 1]),
        ];
    }
}
