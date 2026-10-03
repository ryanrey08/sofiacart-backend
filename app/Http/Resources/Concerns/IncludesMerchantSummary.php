<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Resources\MissingValue;

/**
 * Exposes the owning merchant's store identity when the relation was eager loaded
 * (platform-wide admin listings), so tables can show a store name instead of an ID.
 */
trait IncludesMerchantSummary
{
    /**
     * @return array{id: int, store_name: string, store_slug: string|null}|MissingValue|null
     */
    protected function merchantSummary(): mixed
    {
        return $this->whenLoaded('merchant', fn () => $this->merchant ? [
            'id' => $this->merchant->id,
            'store_name' => $this->merchant->store_name,
            'store_slug' => $this->merchant->store_slug,
        ] : null);
    }
}
