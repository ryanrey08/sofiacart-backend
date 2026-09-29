<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'business_name' => $this->business_name,
            'business_type' => $this->business_type,
            'business_permit_number' => $this->business_permit_number,
            'tin' => $this->tin,
            'business_category' => $this->business_category,
            'business_address' => $this->business_address,
            'city' => $this->city,
            'province' => $this->province,
            'zip_code' => $this->zip_code,
            'business_permit_path' => $this->business_permit_path,
            'store_name' => $this->store_name,
            'store_slug' => $this->store_slug,
            'store_category' => $this->store_category,
            'store_description' => $this->store_description,
            'store_address' => $this->store_address,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'store_logo_path' => $this->store_logo_path,
            'store_banner_path' => $this->store_banner_path,
            'social_links' => $this->social_links,
            'owner_name' => $this->owner_name,
            'owner_position' => $this->owner_position,
            'owner_email' => $this->owner_email,
            'owner_phone' => $this->owner_phone,
            'owner_birth_date' => $this->owner_birth_date?->toDateString(),
            'government_id_type' => $this->government_id_type,
            'government_id_number' => $this->government_id_number,
            'government_id_expiry_date' => $this->government_id_expiry_date?->toDateString(),
            'government_id_path' => $this->government_id_path,
            'status' => $this->status?->value,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
