<?php

namespace App\Services;

use App\Enums\EmailVerificationOutcome;
use App\Enums\UserRole;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Notifications\VerifyMerchantEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Merchant email verification. Each link carries a 64-character random token; only its SHA-256
 * hash is stored, pinned to the address it was sent to. A link works once, expires after
 * `auth.verification.expire` minutes, and is voided when a newer link is issued. Verifying sets
 * users.email_verified_at only: the merchant's approval status is never changed here.
 */
class MerchantEmailVerificationService
{
    /**
     * Mailers that record messages without delivering them; never acceptable in production.
     */
    public const NON_DELIVERING_MAILERS = ['log', 'array'];

    public function expiresInMinutes(): int
    {
        return max(1, (int) config('auth.verification.expire', 60));
    }

    /**
     * Issues a new link for the user's current email address and emails it to that address.
     * Earlier unused links stop working. Returns false when the email could not be handed to
     * the mail provider; the account stays unverified either way.
     */
    public function sendVerificationLink(User $user): bool
    {
        $mailer = (string) config('mail.default');

        if (app()->isProduction() && in_array($mailer, self::NON_DELIVERING_MAILERS, true)) {
            Log::error('Merchant verification email was not sent: the configured mailer does not deliver email.', [
                'user_id' => $user->getKey(),
                'mailer' => $mailer,
            ]);

            return false;
        }

        $token = Str::random(64);

        DB::transaction(function () use ($user, $token): void {
            EmailVerificationToken::where('user_id', $user->getKey())->whereNull('used_at')->delete();

            EmailVerificationToken::create([
                'user_id' => $user->getKey(),
                'email' => $user->email,
                'token_hash' => $this->hash($token),
                'expires_at' => now()->addMinutes($this->expiresInMinutes()),
            ]);
        });

        try {
            $user->notify(new VerifyMerchantEmail($token, $this->expiresInMinutes()));
        } catch (Throwable $exception) {
            Log::warning('Merchant verification email delivery failed.', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class.': '.$exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Sends a new link to an unverified merchant with this address. Does nothing for unknown,
     * non-merchant or already verified addresses, so callers must not reveal the outcome.
     */
    public function resendToAddress(string $email): void
    {
        $user = User::where('email', Str::lower(trim($email)))
            ->where('role', UserRole::Merchant)
            ->whereNull('email_verified_at')
            ->first();

        if ($user) {
            $this->sendVerificationLink($user);
        }
    }

    /**
     * Redeems a link. The token is claimed with a conditional update, so concurrent requests
     * with the same link can verify the account at most once.
     */
    public function verify(string $token): EmailVerificationOutcome
    {
        $record = EmailVerificationToken::with('user')->where('token_hash', $this->hash($token))->first();

        if (! $record || ! $record->user) {
            return EmailVerificationOutcome::Invalid;
        }

        if ($record->used_at !== null) {
            return EmailVerificationOutcome::AlreadyUsed;
        }

        // The address changed after the link was sent; the new address needs its own link.
        if (Str::lower($record->email) !== Str::lower($record->user->email)) {
            return EmailVerificationOutcome::Invalid;
        }

        if ($record->isExpired()) {
            return EmailVerificationOutcome::Expired;
        }

        return DB::transaction(function () use ($record): EmailVerificationOutcome {
            $claimed = EmailVerificationToken::whereKey($record->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            if ($claimed === 0) {
                return EmailVerificationOutcome::AlreadyUsed;
            }

            $user = $record->user;

            EmailVerificationToken::where('user_id', $user->getKey())->whereNull('used_at')->delete();

            if ($user->hasVerifiedEmail()) {
                return EmailVerificationOutcome::AlreadyVerified;
            }

            $user->markEmailAsVerified();
            event(new Verified($user));

            return EmailVerificationOutcome::Verified;
        });
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
