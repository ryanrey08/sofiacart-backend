<?php

namespace App\Enums;

/**
 * Result of redeeming a merchant email verification link. The value is returned to the
 * frontend as `status` so it can show the matching result page.
 */
enum EmailVerificationOutcome: string
{
    case Verified = 'verified';
    case AlreadyVerified = 'already_verified';
    case AlreadyUsed = 'already_used';
    case Expired = 'expired';
    case Invalid = 'invalid';

    public function message(): string
    {
        return match ($this) {
            self::Verified => 'Your email address has been verified.',
            self::AlreadyVerified => 'Your email address is already verified.',
            self::AlreadyUsed => 'This verification link has already been used.',
            self::Expired => 'This verification link has expired. Request a new one to continue.',
            self::Invalid => 'This verification link is invalid or has been replaced by a newer one.',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Verified, self::AlreadyVerified => 200,
            self::AlreadyUsed => 409,
            self::Expired => 410,
            self::Invalid => 422,
        };
    }
}
