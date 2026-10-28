<?php

namespace App\Models;

use App\Enums\RegistrationOptionType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a Super Admin-managed registration dropdown (business type, category, ID type).
 */
class RegistrationOption extends Model
{
    protected $fillable = ['type', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => RegistrationOptionType::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    #[Scope]
    protected function ofType(Builder $query, RegistrationOptionType $type): void
    {
        $query->where('type', $type->value);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Merchant columns holding this option's name, for "in use" counts.
     *
     * @return list<string>
     */
    public static function merchantColumns(RegistrationOptionType $type): array
    {
        return match ($type) {
            RegistrationOptionType::BusinessType => ['business_type'],
            RegistrationOptionType::Category => ['business_category', 'store_category'],
            RegistrationOptionType::GovernmentIdType => ['government_id_type'],
        };
    }
}
