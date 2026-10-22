<?php

namespace App\Enums;

/**
 * Who a legal document applies to: storefront buyers (sofiacart-website) or merchants
 * (merchant registration in sofiacart-frontend).
 */
enum LegalDocumentAudience: string
{
    case Buyer = 'buyer';
    case Merchant = 'merchant';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Buyer',
            self::Merchant => 'Merchant',
        };
    }
}
