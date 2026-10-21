<?php

namespace Tests\Feature;

use App\Enums\MerchantStatus;
use App\Models\EmailVerificationToken;
use App\Models\Merchant;
use App\Models\User;
use App\Notifications\VerifyMerchantEmail;
use App\Services\MerchantEmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_email_is_delivered_to_the_merchants_own_address(): void
    {
        config(['app.frontend_url' => 'https://merchant.sofiacart.test']);
        $user = $this->unverifiedMerchantUser(['name' => 'Maria Santos', 'email' => 'maria@shop.test']);

        $this->assertTrue(app(MerchantEmailVerificationService::class)->sendVerificationLink($user));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame(['maria@shop.test'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
        $this->assertSame('Verify your SofiaCart merchant email address', $email->getSubject());

        $html = $email->getHtmlBody();
        $this->assertMatchesRegularExpression('#https://merchant\.sofiacart\.test/verify-email\?token=[A-Za-z0-9]{64}#', $html);
        $this->assertStringContainsString('Hi Maria Santos,', $html);
        $this->assertStringContainsString('expires in <strong>1 hour</strong>', $html);
        $this->assertStringContainsString('safely ignore this email', $html);
        $this->assertStringContainsString('https://merchant.sofiacart.test/verify-email?token=', $email->getTextBody());

        // Only a hash of the token is stored.
        preg_match('#token=([A-Za-z0-9]{64})#', $html, $matches);
        $this->assertDatabaseMissing('email_verification_tokens', ['token_hash' => $matches[1]]);
        $this->assertDatabaseHas('email_verification_tokens', ['user_id' => $user->id, 'token_hash' => hash('sha256', $matches[1])]);
    }

    public function test_a_valid_link_verifies_the_email_without_approving_the_merchant(): void
    {
        $user = $this->unverifiedMerchantUser();
        $token = $this->issueToken($user);

        $this->postJson('/api/merchant/email/verify', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('status', 'verified')
            ->assertJsonMissingPath('token');

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(MerchantStatus::Pending, $user->merchant->status);
    }

    public function test_a_link_cannot_be_used_twice(): void
    {
        $user = $this->unverifiedMerchantUser();
        $token = $this->issueToken($user);

        $this->postJson('/api/merchant/email/verify', ['token' => $token])->assertOk();

        $this->postJson('/api/merchant/email/verify', ['token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('status', 'already_used');
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = $this->unverifiedMerchantUser();
        $token = $this->issueToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/merchant/email/verify', ['token' => $token])
            ->assertStatus(410)
            ->assertJsonPath('status', 'expired');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unknown_malformed_and_superseded_links_are_rejected(): void
    {
        $user = $this->unverifiedMerchantUser();

        $this->postJson('/api/merchant/email/verify', ['token' => Str::random(64)])
            ->assertStatus(422)
            ->assertJsonPath('status', 'invalid');
        $this->postJson('/api/merchant/email/verify', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');

        $firstToken = $this->issueToken($user);
        $secondToken = $this->issueToken($user);

        $this->postJson('/api/merchant/email/verify', ['token' => $firstToken])
            ->assertStatus(422)
            ->assertJsonPath('status', 'invalid');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $this->postJson('/api/merchant/email/verify', ['token' => $secondToken])->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_link_only_verifies_the_address_it_was_sent_to(): void
    {
        $user = $this->unverifiedMerchantUser(['email' => 'old@shop.test']);
        $token = $this->issueToken($user);
        $user->forceFill(['email' => 'new@shop.test'])->save();

        $this->postJson('/api/merchant/email/verify', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('status', 'invalid');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/merchant/email/verify', ['token' => Str::random(64)])->assertStatus(422);
        }

        $this->postJson('/api/merchant/email/verify', ['token' => Str::random(64)])->assertStatus(429);
    }

    public function test_unverified_merchants_can_sign_in_but_cannot_use_merchant_features(): void
    {
        $user = $this->unverifiedMerchantUser(['email' => 'maria@shop.test', 'password' => 'password']);

        $this->postJson('/api/auth/login', ['email' => 'maria@shop.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.email_verified', false);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.email_verified_at', null);

        $this->getJson('/api/v1/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
        $this->getJson('/api/v1/merchant/profile')->assertForbidden();
        $this->postJson('/api/v1/products', [])->assertForbidden();
    }

    public function test_verified_merchants_continue_through_the_existing_onboarding(): void
    {
        $user = $this->unverifiedMerchantUser();
        $this->postJson('/api/merchant/email/verify', ['token' => $this->issueToken($user)])->assertOk();

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.merchant.status', MerchantStatus::Pending->value);
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/merchant/profile')->assertOk();
    }

    public function test_signed_in_merchant_can_resend_the_link_with_a_cooldown(): void
    {
        Notification::fake();
        $user = $this->unverifiedMerchantUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/merchant/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('status', 'sent');
        Notification::assertSentToTimes($user, VerifyMerchantEmail::class, 1);

        $this->postJson('/api/merchant/email/verification-notification')
            ->assertStatus(429)
            ->assertJsonPath('retry_after', 60);
        Notification::assertSentToTimes($user, VerifyMerchantEmail::class, 1);

        $this->travel(61)->seconds();
        $this->postJson('/api/merchant/email/verification-notification')->assertOk();
        Notification::assertSentToTimes($user, VerifyMerchantEmail::class, 2);
    }

    public function test_resend_reports_an_already_verified_email(): void
    {
        Notification::fake();
        $user = Merchant::factory()->create()->user;
        Sanctum::actingAs($user);

        $this->postJson('/api/merchant/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('status', 'already_verified');
        Notification::assertNothingSent();
    }

    public function test_public_resend_does_not_reveal_whether_an_address_is_registered(): void
    {
        Notification::fake();
        $unverified = $this->unverifiedMerchantUser(['email' => 'pending@shop.test']);
        $verified = Merchant::factory()->create()->user;

        $unknownResponse = $this->postJson('/api/merchant/email/resend', ['email' => 'nobody@shop.test'])->assertOk();
        $verifiedResponse = $this->postJson('/api/merchant/email/resend', ['email' => $verified->email])->assertOk();
        $unverifiedResponse = $this->postJson('/api/merchant/email/resend', ['email' => 'Pending@Shop.test'])->assertOk();

        $this->assertSame($unknownResponse->json(), $unverifiedResponse->json());
        $this->assertSame($verifiedResponse->json(), $unverifiedResponse->json());
        Notification::assertSentToTimes($unverified, VerifyMerchantEmail::class, 1);
        Notification::assertNotSentTo($verified, VerifyMerchantEmail::class);

        // The limit applies to unknown addresses as well, so it can't be used to probe either.
        $this->postJson('/api/merchant/email/resend', ['email' => 'pending@shop.test'])->assertStatus(429);
        $this->postJson('/api/merchant/email/resend', ['email' => 'nobody@shop.test'])->assertStatus(429);
    }

    public function test_public_resend_is_limited_per_address_per_hour(): void
    {
        Notification::fake();
        $user = $this->unverifiedMerchantUser(['email' => 'pending@shop.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/merchant/email/resend', ['email' => 'pending@shop.test'])->assertOk();
            $this->travel(61)->seconds();
        }

        $this->postJson('/api/merchant/email/resend', ['email' => 'pending@shop.test'])->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyMerchantEmail::class, 5);
    }

    public function test_delivery_failures_are_reported_and_leave_the_account_unverified(): void
    {
        $this->useUnreachableMailServer();
        Log::spy();
        $user = $this->unverifiedMerchantUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/merchant/email/verification-notification')
            ->assertStatus(503)
            ->assertJsonPath('status', 'delivery_failed');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'Merchant verification email delivery failed.'
                && $context['user_id'] === $user->id,
        );
    }

    public function test_mock_mailers_are_refused_in_production(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        $this->app['env'] = 'production';
        $user = $this->unverifiedMerchantUser();

        $this->assertFalse(app(MerchantEmailVerificationService::class)->sendVerificationLink($user));
        Notification::assertNothingSent();
    }

    public function test_changing_the_email_requires_verifying_the_new_address(): void
    {
        Notification::fake();
        $user = Merchant::factory()->create()->user;
        $user->forceFill(['password' => 'password'])->save();
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', ['email' => 'new-address@shop.test', 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);

        Notification::assertSentTo(
            $user,
            VerifyMerchantEmail::class,
            fn (VerifyMerchantEmail $notification, array $channels, User $notifiable): bool => $notifiable->routeNotificationFor('mail') === 'new-address@shop.test',
        );
        $this->getJson('/api/v1/categories')->assertForbidden()->assertJsonPath('code', 'email_unverified');
    }

    public function test_changing_only_the_letter_case_of_the_email_keeps_it_verified(): void
    {
        Notification::fake();
        $user = Merchant::factory()->create()->user;
        $user->forceFill(['email' => 'maria@shop.test'])->save();
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', ['email' => 'Maria@Shop.test'])
            ->assertOk()
            ->assertJsonPath('data.email', 'Maria@Shop.test')
            ->assertJsonPath('data.email_verified', true);

        Notification::assertNothingSent();
        $this->getJson('/api/v1/categories')->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function unverifiedMerchantUser(array $attributes = []): User
    {
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Pending]);
        $user = $merchant->user;
        $user->forceFill(['email_verified_at' => null, ...$attributes])->save();

        return $user->fresh();
    }

    protected function issueToken(User $user): string
    {
        Notification::fake();
        app(MerchantEmailVerificationService::class)->sendVerificationLink($user);

        $token = null;
        Notification::assertSentTo($user, VerifyMerchantEmail::class, function (VerifyMerchantEmail $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->assertSame(1, EmailVerificationToken::where('user_id', $user->id)->whereNull('used_at')->count());

        return $token;
    }

    protected function useUnreachableMailServer(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);
    }
}
