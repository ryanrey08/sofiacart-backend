<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A database notification for the in-app notification bell.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (array) $this->data;

        return [
            'id' => $this->id,
            'kind' => $data['kind'] ?? null,
            'title' => $data['title'] ?? null,
            'message' => $data['message'] ?? null,
            'link' => $data['link'] ?? null,
            'data' => $data,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
