<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role?->value,
            'phone' => $this->phone,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'merchant' => MerchantResource::make($this->whenLoaded('merchant')),
        ];
    }
}
