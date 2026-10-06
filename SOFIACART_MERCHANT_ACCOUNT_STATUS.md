# Merchant Account (Personal Profile + Change Password) Status

Last updated: 2026-10-06

A signed-in merchant can edit their personal details and change their password. The account is always the authenticated Sanctum user. No user id is ever accepted from the client.

Store details (store name, business information, branding and documents) are a separate flow that needs approval; see `SOFIACART_MERCHANT_PROFILE_APPROVAL_STATUS.md`.

## What existed before

- **`users` table:** `name` (a single field; there are no first or last name columns), `email` (unique, used to sign in), `phone`, `password` (`hashed` cast, hidden), `role`, `is_active`, `last_login_at`. There is no avatar column.
- **`GET /api/auth/me`:** returns the signed-in user as `UserResource` with the merchant. It never includes the password.
- **No merchant account update or password change endpoint existed.** Admins have their own flows: forgot/reset password under `/api/admin/auth/*`, and user management under `/api/admin/users`.
- **Password rule convention:** `required|confirmed|min:8`, used by merchant registration and the admin reset. The admin reset also rotates `remember_token` and revokes tokens.

## Endpoints

All three are in the existing `auth` group (`auth:sanctum`). The account is `$request->user()`.

| Method | Route | Purpose |
|---|---|---|
| GET | `/api/auth/me` | **Existing, unchanged.** Own account (`id`, `role`, `name`, `email`, `phone`, timestamps, `merchant`) |
| PATCH | `/api/auth/me` | Update own `name`, `email`, `phone`; returns the updated `UserResource` |
| PUT | `/api/auth/password` | Change own password: `current_password`, `password`, `password_confirmation`. Rate-limited with `throttle:account-password` (5 per minute per user). Returns `{ message }` only. |

**Code:**

- `App\Http\Controllers\Api\AccountController`
- `App\Http\Requests\UpdateAccountRequest`
- `App\Http\Requests\ChangePasswordRequest`
- `App\Notifications\AccountPasswordChanged`
- the `account-password` limiter in `AppServiceProvider`

## Validation and security

**Profile update (`PATCH /api/auth/me`):**

- `name` is required, max 255.
- `email` is required, valid, max 255, and unique (ignoring the user's own account).
- `phone` is required and must be a Philippine mobile number (same rule as registration).
- **Changing the email requires `current_password`**, because the email is the login identifier. A different-case version of the same address does not count as a change.
- A changed email resets `email_verified_at` to null. Nothing in the app currently relies on verification.
- These fields are `prohibited` and return 422:
  - `id`, `user_id`, `role`, `is_active`, `status`
  - `password`, `password_confirmation`, `email_verified_at`, `last_login_at`, `remember_token`
  - `merchant`, `merchant_id`

**Change password (`PUT /api/auth/password`):**

- `current_password` is checked with Laravel's `current_password:sanctum` rule against the authenticated user.
- `password` is `required|string|min:8|confirmed|different:current_password`.
- The new password is stored through the model's `hashed` cast (bcrypt). It is never stored in plaintext and never returned.
- On success:
  - `remember_token` is rotated.
  - **Every other token for the user is revoked.** The session making the change stays signed in.
  - An audit row is written.
  - An `AccountPasswordChanged` security notice is sent (in-app plus mail, through `rescue()` so a mail failure never blocks the change).

**Error messages** are specific: "The current password is incorrect.", "The new password confirmation does not match.", "The new password must be different from your current password." Too many attempts return 429.

**Audit (`AdminAuditLogger`):**

- `merchant.account.updated` records field names only.
- `merchant.account.password_changed` records only `other_sessions_revoked`.
- Passwords, hashes and tokens are never logged.

## Authorization

- **Identity:** both write endpoints act only on the token's user, so there is no way to target another account. Sending `id` or `user_id` returns 422.
- **Merchants only:** both form requests `authorize()` only `role = merchant`. Admin tokens get 403, and admins keep their existing `/api/admin/*` flows.
- **No regressions:** the existing admin user management and authentication behave as before (full suite below; `GET /api/admin/users` still returns 200).

## Frontend (`sofiacart-frontend`)

- **New page `/account` ("My Account")**, linked from the sidebar (Account → My Account) and from the top-bar account menu (My account / Store profile). It has:
  - **Personal information:** name, email, mobile number. The current-password field appears only when the email changes. Validated with zod, and server field errors are mapped onto the fields. On success the form resets to the server values and the stored identity (name and email) in the shell is updated.
  - **Change password:** current, new and confirm fields with a "Show passwords" toggle. Client-side checks cover length, match and "different from current". Server errors are mapped onto the fields, with a friendly message for 429. The form clears on success.
  - **Account summary:** role, store, store status, member since, and a link to Store Profile.
- **New files:**
  - `types/account.ts`
  - `lib/validation/account.ts`
  - `lib/hooks/account.ts`
  - `app/(dashboard)/account/page.tsx`
- **Updated files:**
  - `components/sidebar.tsx`
  - `components/top-nav.tsx`

## Tests (actually run)

- **`tests/Feature/MerchantAccountTest.php`: 9 passed (101 assertions).** The tests cover:
  - the merchant can read their own account, and the response has no password or remember token
  - updating allowed fields, with no merchant row duplicated and an audit row of field names
  - a no-op update writes no audit row
  - changing email requires a correct current password and an address no one else uses; same-address case changes are allowed; the merchant can then log in with the new email
  - protected fields return 422, and another user's account is never changed
  - password change rejects a missing or wrong current password, a missing or mismatched confirmation, a password that is too short, and a new password equal to the current one
  - **a successful change:**
    - the stored value is a hash and the response contains no hash
    - the current session works and the other session gets 401
    - the **old password fails to log in and the new one works**
    - an audit row and the notification are written
  - the 6th attempt within a minute returns 429
  - admin tokens get 403 on both endpoints, and `GET /api/admin/users` still works
- **Full backend suite:** 144 passed, 6 failed. The 6 failures existed before this work and are already documented.
- **Pint:** passed.
- **Frontend:**
  - `tsc --noEmit`: passed
  - `eslint .`: clean
  - `next build`: succeeded (`/account` built)
  - `test:smoke`: 1/1 passed
- **Live API check against the Docker MySQL** (merchant `rmayert@example.org` and the seeded Super Admin):
  - the response has no sensitive fields
  - the phone update worked
  - protected fields returned 422, and MySQL still showed role `merchant` and active
  - changing the email without the password returned 422, and a duplicate email returned 422
  - a wrong current password and a mismatched confirmation returned 422
  - a valid change returned 200 with a message only; this session got 200 and the other session got 401
  - the old password failed to log in (422) and the new one worked (200)
  - the stored value is a bcrypt hash, the audit row was written and the notification was created
  - admin tokens got 403, and admin user management returned 200
  - **The original password and phone were then restored through the same endpoints.**

## Blockers / notes

- **No browser click-through:** the browser pane was hidden during this session, so the `/account` UI was verified by type-check, lint, build and the live API calls above.
- **No first/last name or avatar:** these columns don't exist, so the form edits the single `name` field. Adding them would need a migration and is out of scope.
- **Tokens never expire:** merchant Sanctum tokens accumulate per login (the live check revoked 27 old ones). Consider a token expiry or a sign-out-everywhere option.
- **Synchronous notifications:** there is no queue worker, so the security email is sent synchronously (with the `log` mailer in dev).
