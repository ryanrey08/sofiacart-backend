# Merchant Email Verification Status

Last updated: 2026-10-21

Merchants must verify the email address they registered with before they can use merchant features. Email verification and admin approval of the store are **separate**: verifying an email never changes `merchants.status`.

- Backend: `sofiacart-backend`
- Frontend: `sofiacart-frontend`

## What existed before

- `users.email_verified_at` already existed. The `User` model already had Laravel's `MustVerifyEmail` methods (`hasVerifiedEmail()`, `markEmailAsVerified()`) through `Illuminate\Foundation\Auth\User`.
- `POST /api/merchant/register` created the user and the merchant (`status = pending`) in one transaction and sent no email.
- Nothing checked verification. `PATCH /api/auth/me` already reset `email_verified_at` when the email changed.
- Merchant approval is an admin decision (`PATCH /api/admin/merchants/{merchant}/status`). The frontend does not gate pending merchants out of the dashboard.

## Why a custom token instead of Laravel's signed URLs

Laravel's built-in `VerifyEmail` notification uses a signed URL to a backend route. That design has three limits here:

- It expects the signed-in session to be the same user.
- Its links can't be revoked when a newer one is sent.
- A link stays usable until it expires.

This project uses Bearer tokens and a separate frontend, and merchants often open the email on another device. So it keeps Laravel's verification state (`email_verified_at`, `markEmailAsVerified()`, the `Illuminate\Auth\Events\Verified` event) and adds a small table of single-use tokens.

## Flow

1. **Register.** The merchant submits the registration form (`/register/merchant`).
   - The backend lowercases and trims the email.
   - It validates the request, then creates the user with `email_verified_at = null` and the merchant with `status = pending`.
2. **Email sent.** After the transaction commits, the backend emails a link to the exact address that was registered: `{FRONTEND_URL}/verify-email?token=<64 random chars>`.
   - The response includes `verification_email_sent` and `verification_expires_in_minutes`. It never includes the token.
   - If the email can't be sent, registration still succeeds with `verification_email_sent: false`, and the merchant can resend.
3. **Check your email screen.** The frontend shows the destination address, the link lifetime and a **Resend** button. The button starts with a 60-second cooldown.
4. **Open the link.** The merchant opens the link, and `/verify-email` sends the token to `POST /api/merchant/email/verify`.
   - Each token is posted only once per page load, so React's double-invoked effects can't use up the link.
   - Afterwards the token is removed from the address bar and browser history. The page also sends `Referrer-Policy: no-referrer`.
5. **Result.** The backend validates the token, marks it used, sets `email_verified_at`, and fires `Verified`. The merchant's approval `status` is not touched.
6. **Result page.** The frontend shows one of: verified, already verified, already used, expired, invalid. Expired and invalid pages offer a new link.
7. **Continue.** The merchant signs in, or continues to the dashboard if already signed in. Onboarding is unchanged: a pending merchant sees their dashboard with status "Pending" until an admin approves.

**Signed-in but unverified merchants:**

- Sign-in works. The login response's `user.email_verified` is `false`, so the frontend goes to `/verify-email` instead of the dashboard.
- That page reads status from `GET /api/auth/me`. It offers **I've verified my email** (re-checks the backend), **Resend**, **Wrong email address?** (uses `PATCH /api/auth/me`, which needs the current password and sends a new link), and **Sign out**.

## Endpoints

| Method | Route | Auth | Purpose |
|---|---|---|---|
| POST | `/api/merchant/register` | public | **Existing.** Now emails a verification link and returns `verification_email_sent`, `verification_expires_in_minutes` and `user.email_verified`. Returns 409 if a concurrent registration claims the same email, TIN or store URL after validation. |
| POST | `/api/merchant/email/verify` | public | Body `{ token }`. Returns `{ message, status }` (status codes below). `throttle:merchant-email-verify` |
| POST | `/api/merchant/email/resend` | public | Body `{ email }`. Always returns 200 with the same message. Sends only if an unverified merchant has that address. `throttle:merchant-email-resend` |
| POST | `/api/merchant/email/verification-notification` | `auth:sanctum` | Resend to the signed-in merchant's own address. Returns `status`: `sent`, `already_verified`, or `delivery_failed` (503). `throttle:merchant-email-resend` |
| GET | `/api/auth/me` | `auth:sanctum` | **Existing.** `UserResource` now includes `email_verified` (bool) and `email_verified_at`. |

**Responses from `POST /api/merchant/email/verify`:**

| status | HTTP | Meaning |
|---|---|---|
| `verified` | 200 | Email verified now |
| `already_verified` | 200 | Token was valid, but the account was already verified |
| `already_used` | 409 | Token was already redeemed |
| `expired` | 410 | Token is past `expires_at` |
| `invalid` | 422 | Unknown token, token replaced by a newer link, or the account's email changed after the link was sent |

A missing or malformed `token` returns the standard Laravel 422 validation response.

**Blocked merchant features:** every route in the `api/v1` group now runs `auth:sanctum` and then `merchant.verified` (`EnsureMerchantEmailIsVerified`). An unverified **merchant** gets:

```json
403 { "message": "Verify your email address to use merchant features.", "code": "email_unverified" }
```

Admin users are not affected. These routes stay available to unverified merchants: `/api/auth/*` (me, account update, password, logout), and the merchant email routes.

## Code

**Backend:**

| What | Where |
|---|---|
| Token issue, verify and resend logic | `App\Services\MerchantEmailVerificationService` |
| Endpoints | `App\Http\Controllers\Api\MerchantEmailVerificationController` |
| Request validation | `App\Http\Requests\VerifyMerchantEmailRequest`, `App\Http\Requests\ResendMerchantVerificationRequest` |
| Middleware (alias `merchant.verified`, registered in `bootstrap/app.php`) | `App\Http\Middleware\EnsureMerchantEmailIsVerified` |
| Token model | `App\Models\EmailVerificationToken` |
| Outcome codes | `App\Enums\EmailVerificationOutcome` |
| Email notification | `App\Notifications\VerifyMerchantEmail` |
| Email templates (HTML and plain text) | `resources/views/mail/merchant-email-verification.blade.php`, `resources/views/mail/merchant-email-verification-text.blade.php` |
| Migration (additive only) | `database/migrations/2026_10_21_010158_create_email_verification_tokens_table.php` |
| Rate limiters | `merchant-email-verify` and `merchant-email-resend` in `AppServiceProvider` |
| Link lifetime setting | `auth.verification.expire` in `config/auth.php` |

**Changed backend files:**

- `MerchantController::register`: sends the verification email.
- `RegisterMerchantRequest`: normalizes the email.
- `AccountController::update`: a real address change requires verifying the new address.
- `UserResource`: adds the verification fields.
- `routes/api.php`

**Frontend:**

| What | Where |
|---|---|
| `/verify-email` page | `app/(auth)/verify-email/page.tsx` |
| Result, pending and resend screens; the "check your email" card | `components/auth/email-verification.tsx` |
| Verify and resend calls; syncing the stored session from `/api/auth/me` | `lib/hooks/email-verification.ts` |
| Pure helpers (tested in `tests/email-verification.test.mjs`) | `lib/email-verification.ts` |

**Changed frontend files:**

- `components/auth/merchant-registration-form.tsx`: the success screen is now the "check your email" card.
- `components/auth/login-form.tsx`: unverified merchants go to `/verify-email`.
- `components/auth/auth-guard.tsx`: reads status from the backend on load. Redirects unverified merchants to `/verify-email`, also when any API call returns `email_unverified`.
- `lib/api/axios.ts`: raises an event on `email_unverified`.
- `lib/hooks/account.ts`: stores the verification flag after an account update.
- `types/index.ts`, `types/account.ts`

**Admins:** they see `user.email_verified` / `email_verified_at` in the admin merchant view, through `AdminMerchantResource` → `UserResource`. Admin approval does not require a verified email. That was left as is because the request was to keep the two processes separate.

## Token expiration and resend limits

| Setting | Value |
|---|---|
| Link lifetime | `AUTH_VERIFICATION_EXPIRE` minutes (default **60**) |
| Uses per link | **1** |
| Issuing a new link | Deletes all unused links for that user, so only the newest link works |
| Successful verification | Deletes the user's other unused links |
| Verify attempts | **10 per minute per IP** |
| Resend, per address (public) or per user (signed in) | **1 per minute** and **5 per hour** |
| Resend, per IP | **10 per minute** |
| Over-limit response | `429 { message, retry_after }`. `retry_after` is in the body because CORS doesn't expose the `Retry-After` header to the frontend. |
| Frontend cooldown | Shows the countdown on the button and respects `retry_after` |

## Security behaviour

- **Unguessable tokens.** Tokens come from `Str::random(64)` (CSPRNG). Only `hash('sha256', token)` is stored. The plain token exists only in the email and is never returned by the API or written to logs by this code.
- **Each link verifies one address.** A token verifies only the user it was issued to, and only while the account's email still matches the address the link was sent to.
- **Concurrent use.** Tokens are claimed with a conditional `UPDATE … WHERE used_at IS NULL`, so two simultaneous requests can't both verify.
- **Email changes.** Changing the email through `PATCH /api/auth/me` clears the verified status and sends a link to the new address. Changing only the letter case keeps it verified. Links sent to the old address stop working.
- **Address privacy.** Public resend returns the same body for unknown, verified and unverified addresses. The email is sent after the response (`defer()`), so response timing doesn't reveal the outcome either. Rate limits apply to unregistered addresses too.
  - Registration itself still reports "email has already been taken". That is the existing behaviour, and the merchant needs it to fix the form.
- **Verification is not approval.** It never changes `merchants.status`; tests assert this.
- **No mock delivery in production.** If `APP_ENV=production` and `MAIL_MAILER` is `log` or `array`, no link is issued. An error is logged and the API reports the email as not sent.
- **Delivery failures.** These are logged with the user id and the exception, never the token. Registration still succeeds. The signed-in resend returns 503 `delivery_failed`.
- **Duplicate registrations.**
  - The email is normalized before the `unique:users,email` check, so `Owner@x.com` and `owner@x.com` can't both register.
  - The TIN and store slug unique rules are unchanged.
  - A race that passes validation hits the database unique index and returns 409. No duplicate user or merchant is created.
- **Sending is synchronous.** The Docker stack runs no queue worker, and this keeps the token out of the jobs table.

## Environment variables

| Variable | Purpose |
|---|---|
| `MAIL_MAILER` | `smtp` (or `ses`, `postmark`, `resend`, …) in production. `log` locally writes the full email, including the link, to `storage/logs/laravel.log`. |
| `MAIL_SCHEME` | `smtps` for implicit TLS (port 465). Leave `null` for STARTTLS or plain. |
| `MAIL_HOST`, `MAIL_PORT` | SMTP server, e.g. `smtp.mailgun.org` / `587` |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP credentials. Set them in the environment only; they are never committed. |
| `MAIL_FROM_ADDRESS` | Sender address, e.g. `no-reply@sofiacart.com`. Must be allowed by the provider (SPF/DKIM). |
| `MAIL_FROM_NAME` | Sender name. `.env.example` defaults to `${APP_NAME}` = "SofiaCart Backend", so set `MAIL_FROM_NAME="SofiaCart"` for merchant-facing mail. |
| `FRONTEND_URL` | Base URL used in the link (`{FRONTEND_URL}/verify-email?token=…`). Default `http://localhost:3000`. **Must be the public merchant frontend URL in production.** |
| `AUTH_VERIFICATION_EXPIRE` | Link lifetime in minutes (default 60) |

Frontend: `NEXT_PUBLIC_API_URL` (existing) must point at the backend.

**Example SMTP configuration:**

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=no-reply@sofiacart.com
MAIL_FROM_NAME="SofiaCart"
FRONTEND_URL=https://merchant.sofiacart.com
AUTH_VERIFICATION_EXPIRE=60
```

After changing `.env` in Docker, run `docker exec sofiacart-app php artisan config:clear`.

**SofiaCart's own mailbox (`admin@sofiacart.ph`).** It is GoDaddy Professional Email, which uses Titan webmail. It must send through **`smtpout.secureserver.net`** (port 465, `MAIL_SCHEME=smtps`) with the mailbox password. `smtp.titan.email` rejects these logins with `535 authentication failed`, even though the password works in webmail. This is the same setup `sofiacart-website-backend` uses. It was confirmed on 2026-10-21: a test email was accepted from the merchant backend container.

## Tests executed (2026-10-21)

**Backend: `php artisan test --compact`**

- **170 tests: 165 passed, 5 failed.**
- The 5 failures also fail on the untouched code (checked with `git stash`), so they are not caused by this change:
  - `OrderWorkflowTest::test_order_creation_rejects_inactive_or_draft_products`
  - `ProductCrudTest::test_merchant_can_crud_products`
  - `ProductCrudTest::test_sku_must_be_unique_but_an_existing_product_can_keep_its_sku`
  - `ProductCrudTest::test_product_images_upload_and_replace_through_multipart_requests`
  - `ReturnRequestWorkflowTest::test_variant_stock_and_order_finances_are_server_owned_and_cancellation_restores_stock`
- Focused run, `MerchantEmailVerificationTest`, `MerchantRegistrationTest`, `MerchantAccountTest` and `AuthenticationTest`: **32/32 passed**.

**New backend tests:**

- `tests/Feature/MerchantRegistrationTest.php`:
  - successful registration (unverified, pending, email sent only to the registered, normalized address, no token in the response)
  - duplicate registrations (case-insensitive email, TIN, store slug; one user and one merchant)
  - registration still succeeds when mail delivery fails
- `tests/Feature/MerchantEmailVerificationTest.php`:
  - delivery through the `array` mailer: recipient, subject, link, greeting, expiry and ignore note in the rendered email; only the hash is stored
  - successful verification does not approve the merchant
  - reused, expired, unknown, malformed and replaced tokens
  - a link stops working after an email change
  - verify rate limit
  - unverified merchants can sign in but get 403 `email_unverified` on `v1` routes
  - verified pending merchants continue onboarding
  - signed-in resend with cooldown, and the already-verified case
  - public resend gives the same response for any address, and is rate limited per minute and per hour
  - delivery failure returns 503 and logs a warning
  - mock mailers refused in production
  - email change requires re-verification; a case-only change does not

**Frontend:**

| Command | Result |
|---|---|
| `npm run lint` | pass (exit 0) |
| `npx tsc --noEmit` | pass (exit 0) |
| `npm run build` | pass; `/verify-email` is listed as a dynamic route |
| `npm run test:unit` | **not runnable on this machine** |

The unit tests import `.ts` files directly. That needs Node ≥ 22.6, and only Node 20.18 is installed, so all 8 existing suites already fail with `ERR_UNKNOWN_FILE_EXTENSION` before this change. The new `tests/email-verification.test.mjs` is added to `test:unit` but has not been run.

**Manual end-to-end check** against the local Docker backend (`localhost:8000`) and the running Next dev server (`localhost:3000`):

- Registered a test merchant via the API. The response had `verification_email_sent: true` and no token. The log mailer showed the email addressed to the registered (lowercased) address.
- Signing in redirected to `/verify-email`, and opening `/sales/products` redirected back there.
- Resend showed a success message and a countdown.
- "I've verified my email" reported not verified yet.
- The link replaced by the resend showed "Link not valid". The newest link showed "Email verified", and opening it again showed "Link already used".
- After verifying, the dashboard loaded with merchant status still "Pending".
- The signed-out `/verify-email` page at 375px width rendered correctly. Public resend for an unknown address showed the neutral message.

**Not exercised in a browser:** the post-registration "check your email" card (the form needs file uploads), the expired-link page, and the change-email form. These are covered by the build and typecheck, and the backend tests cover their API behaviour.

## Remaining setup steps

1. **Run the migration** in each environment: `php artisan migrate` (`docker exec sofiacart-app php artisan migrate`). It has already been run on the local Docker database.
2. **Configure a real mail provider** for staging and production (variables above) and set `FRONTEND_URL` to the public merchant frontend. Until then, production refuses to send links and merchants can't verify.
3. **Set `MAIL_FROM_NAME="SofiaCart"`** and a sender domain with SPF/DKIM so the email isn't marked as spam.
4. **Existing merchants.** Merchant users with `email_verified_at = null` will be sent to `/verify-email` and must verify before using merchant features. Locally, 1 of 15 merchants is unverified.
   - Accounts were deliberately **not** auto-marked as verified, because only a real backend verification may set that.
   - If you decide to treat existing accounts as already verified, do it as an explicit, reviewed data migration.
5. **Optional:** schedule cleanup of old `email_verification_tokens` rows. Used rows are kept so "already used" can be reported. Expired unused rows are replaced on the next resend.
6. **Optional:** run the frontend unit tests on Node ≥ 22.6.
7. **Local test data.** The manual check created the merchant `verify.tester@sofiacart.test` (store slug `verify-test-store`, TIN `TIN-VT-001`) in the local Docker database. Delete it if you don't want it.
