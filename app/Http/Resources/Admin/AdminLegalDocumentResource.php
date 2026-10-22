<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of one legal document version. The storage path is never included.
 */
class AdminLegalDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'audience' => $this->audience->value,
            'type' => $this->type->value,
            'title' => $this->type->label(),
            'version' => $this->version,
            'version_label' => $this->version_label,
            'effective_date' => $this->effective_date?->toDateString(),
            'status' => $this->status->value,
            'is_published' => $this->resource->isPublished(),
            'format' => $this->resource->format(),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'checksum' => $this->checksum,
            'notes' => $this->notes,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->person($this->uploader)),
            'published_by' => $this->whenLoaded('publisher', fn () => $this->person($this->publisher)),
            'unpublished_by' => $this->whenLoaded('unpublisher', fn () => $this->person($this->unpublisher)),
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
            'unpublished_at' => $this->unpublished_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    protected function person(mixed $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
