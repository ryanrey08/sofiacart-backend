# Merchant Profile Approval Status

Last updated: 2026-10-06

Merchants edit their store details, and can replace their logo, banner or documents. The edits are stored as a **pending change request** until a Super Admin approves them. The live `merchants` row and the live files change only on approval.

## What existed before

- **Merchant record:** `merchants` (one row per store). The merchant UI reads it only through `GET /api/auth/me` (`UserResource` → `MerchantResource`).
- **Merchant self-edit:** there was no merchant update endpoint and no profile page in the frontend.
- **Approval:** the existing approval flow covers onboarding status only (`PATCH /api/admin/merchants/{id}/status`). It writes `AdminAuditLog` rows `admin.merchants.*`, and these rows feed the merchant's "Approval / rejection history".
- **Draft or change-request storage:** none existed.
- **Notifications:** none existed. `User` was `Notifiable`, but there was no notifications table, no notification classes and no notification UI. Mail uses the `log` mailer in development.
- **Queue:** there is no queue worker in `docker-compose.yml`, so notifications are sent synchronously.

## Workflow

```
Merchant edits/uploads → POST change request (multipart)
      text  ─▶ merchant_change_requests.changes          files ─▶ private `local` disk (merchant-change-requests/{id}/…)
      merchants row + live files: unchanged               admins with merchants.manage notified
Merchant withdraw  ─▶ status withdrawn, pending files deleted
Admin approve      ─▶ transaction: lock request + merchant → apply text → copy files to `public` disk
                       (merchants/{slug}/…) and point the merchant's *_path columns at them → request approved
                       → pending copies deleted → merchant notified
Admin reject       ─▶ request rejected + reason → pending files deleted → merchant notified; merchants row unchanged
```

- **Stored changes:** only fields that differ from the approved value are stored. Values are trimmed, blanks become null, line endings are normalized to `\n`, and social links drop empty entries.
  - Line-ending normalization matters because multipart form submissions send `\r\n`. Without it, an unchanged multi-line address counts as a change. This bug was found in the live check and has a regression test.
- **One pending request per merchant:** this is enforced while the merchant row is locked. A second submission returns 422 (`changes`) until the first is reviewed or withdrawn. Submitting with no differences and no files also returns 422.
- **Decisions are final:** approve, reject and withdraw all lock the request. A request that is no longer pending returns 422 (`status`), so it is never applied twice.
- **Editable text fields:**
  - store: `store_name`, `store_category`, `store_description`, `store_address`
  - business: `business_name`, `business_type`, `business_category`, `business_address`, `city`, `province`, `zip_code`
  - contact: `contact_phone`, `contact_email`, `social_links`
  - owner: `owner_name`, `owner_position`, `owner_email`, `owner_phone`
- **Replaceable files** (same limits as registration):

  | File | Allowed types | Max size |
  |---|---|---|
  | `store_logo` | jpg/png | 2 MB |
  | `store_banner` | jpg/png | 5 MB |
  | `business_permit` | pdf/jpg/png | 5 MB |
  | `government_id` | pdf/jpg/png | 5 MB |

- **Unapproved files are never public:** they live on the private `local` disk and are streamed only through authenticated endpoints. The resource never exposes their storage path.
- **Previous live files are kept** on the public disk after a replacement is approved. Nothing is deleted from live storage.
- **Admin-controlled fields (`prohibited`, 422):**
  - `status`, `user_id`, `tin`, `store_slug`, `business_permit_number`, `owner_birth_date`
  - the government ID number, type and expiry
  - the raw `*_path` columns
- **Document numbers are not updated by a file replacement:** a new permit or ID file does not change the permit number, ID number or expiry, which stay admin-controlled.

## Notifications

These are Laravel Notifications on the `database` and `mail` channels. They are sent **after** the transaction commits, and wrapped in `rescue()`, so a mail failure is reported but never blocks or undoes a decision.

| Event | Recipients | Kind |
|---|---|---|
| Merchant submits | Active admins with `merchants.manage` (Super Admin, Merchant Manager). View-only and deactivated admins are excluded. | `merchant_profile_change_submitted` → link `/admin/merchants/{id}` |
| Admin approves | Merchant owner | `merchant_profile_change_approved` → link `/store-profile` |
| Admin rejects | Merchant owner (includes the reason) | `merchant_profile_change_rejected` → link `/store-profile` |

- **Email links** use `config('app.frontend_url')`, read from `FRONTEND_URL` (default `http://localhost:3000`; added to `.env.example`).
- **Mail delivery in development:** the mailer is `log`, so emails are written to the Laravel log rather than delivered.

## Database

Three additive migrations, all applied to the local Docker MySQL. All 16 merchants were intact afterwards.

1. `2026_10_06_000000_create_merchant_change_requests_table` adds these columns:
   - `merchant_id`, `status` (`pending` / `approved` / `rejected` / `withdrawn`)
   - `changes` and `original` (JSON)
   - `submitted_by`, `reviewed_by`, `reviewed_at`, `rejection_reason`
   - timestamps
   - indexes `(merchant_id, status)` and `(status, created_at)`
2. `2026_10_06_010000_add_files_and_withdrawn_at_to_merchant_change_requests_table` adds:
   - `files` (JSON: path, name, mime and size per document)
   - `withdrawn_at`
3. `2026_10_06_020000_create_notifications_table` creates Laravel's standard database-notification table.

## API

**Merchant routes** (`/api/v1`, `auth:sanctum`; profile routes are for merchant accounts only, and admins get 403):

| Method | Route | Purpose |
|---|---|---|
| GET | `/merchant/profile` | Approved (live) record, plus `meta.pending_change_request`, `meta.latest_change_request` and `meta.editable_fields` |
| GET | `/merchant/profile/documents/{document}` | Stream one of the merchant's own live files |
| GET | `/merchant/profile/change-requests` | Own requests, paginated |
| GET | `/merchant/profile/change-requests/{id}` | Own request; another merchant's request returns 404 |
| POST | `/merchant/profile/change-requests` | Submit text fields and/or files (multipart); returns 201 |
| POST | `/merchant/profile/change-requests/{id}/withdraw` | Withdraw an own pending request |
| GET | `/merchant/profile/change-requests/{id}/files/{document}` | Preview an own pending upload |
| GET | `/notifications` (`?unread=1`) | Own notifications, plus `meta.unread_count` |
| POST | `/notifications/{id}/read`, `/notifications/read-all` | Mark one or all as read |

**Admin routes** (`/api/admin`, existing admin middleware):

| Method | Route | Permission |
|---|---|---|
| GET | `/merchant-change-requests` (filters `status`, `merchant_id`, `search`, `sort`) | `merchants.view` |
| GET | `/merchant-change-requests/{id}` (includes `comparison` rows with `kind` set to `text` or `file`) | `merchants.view` |
| GET | `/merchant-change-requests/{id}/files/{document}` | `merchants.view` |
| PATCH | `/merchant-change-requests/{id}/status` with `{status: approved\|rejected, reason}`; `reason` is required to reject. Returns `meta.merchant`. | `merchants.manage` |
| GET/POST | `/notifications`, `/notifications/{id}/read`, `/notifications/read-all` | admin token; scoped to the admin user |

**Updated admin routes:**

- `GET /merchants` adds the `has_pending_changes` filter.
- Merchant list and detail responses add `pending_change_request`.
- `GET /merchants/summary` adds `pending_profile_changes`.

## Authorization

`MerchantChangeRequestPolicy` controls who can do what:

- **Merchants** can create requests, and view and withdraw **their own**. Another merchant's request returns 404.
- **`review`** is admin-only: it needs an `admin` token and `merchants.manage`.
- **Admins can never withdraw** a merchant's request through the merchant API.
- **Route middleware** also enforces the admin permissions.
- **Notifications** are always queried through `$request->user()`, so a user can never read or mark someone else's.

## Audit

`AdminAuditLogger` records each step:

- `merchant.profile.change_requested`
- `merchant.profile.change_withdrawn`
- `admin.merchants.profile_change_approved`
- `admin.merchants.profile_change_rejected` (adds `reason`)

The metadata holds the change request id and **field or document names only**, never values or file paths.

The excluded routes below would otherwise produce duplicate or noisy entries:

- The review PATCH is excluded from the generic `AuditAdminMutation` log, because the service logs it with more detail.
- Marking admin notifications read is also excluded.

## Frontend (`sofiacart-frontend`)

- **`/store-profile` (merchant):**
  - the editable form
  - a **Branding & documents** card showing each current file, a replace picker with client-side type and size checks, and the selected file
  - a **Pending Approval** banner with the comparison, side-by-side previews of the current and requested file, and **Withdraw request** (with an inline confirm)
  - a Rejected banner with the reason, or an approved notice
  - request history, including withdrawn requests
- **Notification bell** in both the merchant top bar and the admin header (`components/notification-bell.tsx`):
  - shows an unread badge and the 8 latest notifications
  - clicking one marks it read and opens its link; "Mark all as read" is available
  - polls every 60 s and on window focus (the backend has no realtime channel)
- **Admin merchant detail:** the review panel shows file rows and side-by-side previews of the current and requested file.
  - Fix to the existing "Uploaded documents" panel: it now reloads after a document is replaced. It was keyed by a name that never changed, so it showed a stale "not available".
- **New files:**
  - `types/merchant-profile.ts`
  - `types/notifications.ts`
  - `lib/merchant-profile.ts`
  - `lib/validation/merchant-profile.ts`
  - `lib/hooks/merchant-profile.ts`
  - `lib/notifications.ts`
  - `components/merchant/profile-change-comparison.tsx`
  - `components/admin/merchant-change-review.tsx`
  - `components/notification-bell.tsx`
- **Updated files:**
  - `lib/api/admin.ts`
  - `types/admin.ts`
  - `components/sidebar.tsx`
  - `components/top-nav.tsx`
  - `components/admin/admin-shell.tsx`
  - `components/admin/merchant-application.tsx`
  - the admin merchant list and detail pages

## Tests (actually run)

- **`tests/Feature/MerchantProfileChangeRequestTest.php`: 12 passed (219 assertions).**
  - The original 6 cover:
    - pending vs live data (ABC Store → ABC Trading)
    - rejection with a reason
    - prohibited fields and duplicate requests
    - cross-merchant 404s
    - the role permissions
    - social links
  - The new tests cover:
    - **Files:** uploads stay on the private disk while pending and the live path is unchanged. Merchants can preview their own uploads and other merchants get 404. The admin preview works and the comparison has file rows. On approval the files are published to the public disk, the paths are updated, the old file is kept and the pending copies are deleted.
    - **Reject and invalid uploads:** rejecting discards the pending file. Invalid type or size returns 422 and stores nothing.
    - **Withdraw:**
      - the owner can withdraw; other merchants get 404 and admins get 403
      - withdrawing deletes the files, writes an audit row, returns 422 if repeated, and blocks a later admin approval
      - a new request can then be submitted
    - **Notification recipients:**
      - Super Admin and Merchant Manager receive submission notices; view-only and deactivated admins do not
      - the merchant receives approval and rejection notices
      - both the database and mail channels are used
    - **In-app notification API:** listing, `unread_count`, marking one read and marking all read. A user cannot read another user's notification, and marking admin notifications read writes no audit row.
    - **Regression:** `\r\n` line endings in a multipart submission are not treated as changes.
- **Full backend suite:** 135 passed, 6 failed. The 6 failures existed before this work and are documented: product PATCH nulls the price, the GD build lacks webp, and an order validation key.
- **Pint:** passed for all touched files.
- **Frontend:**
  - `tsc --noEmit`: passed
  - `eslint .`: clean
  - `next build`: succeeded
  - `test:smoke`: 1/1 passed
  - `test:unit`: cannot run here. Node v20.18.1 can't import `.ts` files and Node ≥ 22.6 is needed; this predates this work.
- **Live browser check on the dev MySQL** (merchant #2 and the seeded Super Admin):
  1. The merchant uploaded a new logo. The in-app browser can't operate the native file picker, so the file was placed in the input with `DataTransfer`; the form, `FormData` and POST ran unmodified. The pending banner showed side-by-side previews.
  2. The admin bell showed 2 unread. Clicking one marked it read and opened the merchant.
  3. The admin previewed the logo and approved. The live logo was replaced, and the old and pending copies were handled as described above.
  4. The merchant bell showed "Profile changes approved", and "Mark all as read" cleared it.
  5. Withdraw was tested twice: request #4 (which surfaced the line-ending bug) and request #6.
- **Bugs found and fixed during the live check:**
  - line endings (above)
  - the withdraw success message did not show (the per-call `mutate()` callback was skipped when the component unmounted)
  - the stale "Uploaded documents" panel

## Data left in the dev database by testing

- **Merchant #2's names** are back to their original values.
- **Merchant #2's `store_logo_path`** now points to the approved test image (a purple "NEW" square). The previous path never existed on disk.
- **Test rows:** change requests #1–#6, with their audit-log and notification rows, remain as test history.

## Remaining / blockers

- **Notifications are synchronous and polled:**
  - There is no queue worker. Run `php artisan queue:work` and make the notifications `ShouldQueue` once one exists.
  - There is no websocket channel, so the bell polls every 60 s.
- **No email or notification on withdraw:** admins see the request disappear from the queue, but get no message.
- **Replaced files are not cleaned up:** previous live files are kept after a replacement (deliberately non-destructive), and there is no cleanup job for them.
- **Pre-existing document 404s:** seeded merchants' document paths don't exist on disk, so their previews show "not available". This is a data issue, not part of this work.
