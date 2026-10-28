<?php

namespace App\Enums;

/**
 * Super Admin-managed dropdown lists used by merchant registration and the Store Profile form.
 */
enum RegistrationOptionType: string
{
    case BusinessType = 'business_type';
    // One shared list for both the business category and the store category.
    case Category = 'category';
    case GovernmentIdType = 'government_id_type';

    public function label(): string
    {
        return match ($this) {
            self::BusinessType => 'Business types',
            self::Category => 'Categories',
            self::GovernmentIdType => 'Government ID types',
        };
    }
}
