<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminAuditLogResource extends JsonResource
{
    protected const REDACTED_PLACEHOLDER = '[REDACTED]';

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'description' => $this->description,
            'actor' => AdminUserResource::make($this->whenLoaded('actor')),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'metadata' => $this->sanitizeMetadata($this->metadata),
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    protected function sanitizeMetadata(mixed $metadata): mixed
    {
        if (! is_array($metadata)) {
            return $metadata;
        }

        return collect($metadata)
            ->map(function (mixed $value, string|int $key): mixed {
                if (is_string($key) && $this->containsSensitiveKey($key)) {
                    return self::REDACTED_PLACEHOLDER;
                }

                return is_array($value) ? $this->sanitizeMetadata($value) : $value;
            })
            ->all();
    }

    protected function containsSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (['password', 'token', 'secret', 'authorization', 'api_key', 'apikey'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
