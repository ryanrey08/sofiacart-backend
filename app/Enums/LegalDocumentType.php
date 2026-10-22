<?php

namespace App\Enums;

enum LegalDocumentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';

    public function label(): string
    {
        return match ($this) {
            self::Terms => 'Terms and Conditions',
            self::Privacy => 'Data Privacy Policy',
        };
    }

    /**
     * What a registrant does with this document: the Terms are accepted (a contract), the Privacy
     * Policy is acknowledged as read. The acknowledgment is not blanket consent to processing.
     */
    public function consentAction(): string
    {
        return match ($this) {
            self::Terms => 'accepted',
            self::Privacy => 'acknowledged',
        };
    }
}
