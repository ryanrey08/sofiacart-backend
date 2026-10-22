# SofiaCart — Legal Documents (Terms and Conditions, Data Privacy Policy)

Status as of 2026-10-22. Spans four repositories under `sofia-cart/`:
`sofiacart-backend` (admin/merchant API), `sofiacart-frontend` (Super Admin + merchant UI),
`sofiacart-website-backend` (customer API), `sofiacart-website` (customer site). Nothing is committed.

## 1. Source documents used

The official source documents are the four Word files the user supplied (they are `.docx`, not
PDFs). At the user's request the system accepts **both PDF and Word (.docx)**, so the files are
stored and served exactly as supplied. They are never converted, retyped, summarized or truncated.

| Slot | File (`~/Downloads`) | Size | SHA-256 |
| --- | --- | --- | --- |
| Buyer Terms and Conditions | `Buyer Terms and Condition - SofaCart.docx` | 26,090 B | `3635262213f3a7f0924256bca9da201d5432fc01c93f78b48b2725f4335c39d1` |
| Buyer Data Privacy Policy | `Buyer Data Privacy - SofiaCart.docx` | 25,091 B | `de499d54cd32ad98f31eaffd166c131ee48c8d5053ec2aa41e9184159e1936c4` |
| Merchant Terms and Conditions | `Merchant Terms and Condition - SofiaCart.docx` | 23,346 B | `124c0e4bb33361ba56fbdfc48b676bc32a5e4610869782e2ee0854ce161dc340` |
| Merchant Data Privacy Policy | `Merchant Data Privacy Act - SofiaCart.docx` | 23,522 B | `9e4dedfb16a455e23304d602a7df727a42baa0546eb894370f5d491daedd2408` |

Each document says "Effective Date: June 18, 2026" and "Version 1.0". They were imported into the
**local Docker database** as version 1 (label `1.0`, effective date 2026-06-18) and published with
`legal-documents:import` (see §8). The stored checksums match the originals byte for byte, and the
files served by both public APIs hash to the same values.

Because the documents come in two audiences, the system manages **four slots**: buyer and
merchant × terms and privacy.

## 2. Management workflow (Super Admin)

Super Admin → **Platform → Legal Documents** (`/admin/legal-documents` in `sofiacart-frontend`,
inside the existing admin layout and sidebar). There are Buyers and Merchants tabs, each with a
card for the Terms and one for the Privacy Policy. A card shows:

- the status (Published · vN / Not published), last-updated time, current version and label,
  effective date, who published it and when, and the file name, format and size;
- **View current**: an in-panel preview of the published file;
- **Upload document / Upload replacement**: PDF or .docx up to 10 MB, plus optional version label,
  effective date and internal notes. The upload becomes a **private draft**, and its preview opens
  right away;
- a **Draft … awaiting review** banner with **Preview draft**. From the preview you can choose
  **Publish this version**, which asks for confirmation;
- **Unpublish**, which asks for confirmation and warns that registration pauses;
- **Version history**: every version with its status, file, uploader and publisher. Any version
  can be previewed, and any non-published version can be published, which also allows a rollback.

PDFs are shown in the browser's PDF viewer. Word files are rendered client-side from the file
itself with `docx-preview` 0.3.7. That library only displays the file; it does not change it. Two
display-only adjustments are made: pages are scaled to fit narrow screens, and list-marker glyphs
in the Windows Symbol/Wingdings fonts (which browsers can't draw) become standard bullets. Links
inside a rendered file are limited to http(s)/mailto/tel.

## 3. Endpoints

### sofiacart-backend (admin/merchant API, `:8000`)

These sit in the admin group (`auth:sanctum`, `admin`, `admin.token`, `admin.audit`) with per-route
permissions:

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/api/admin/legal-documents` | `legal.view` | The 4 slots, each with its published and latest version and a version count |
| GET | `/api/admin/legal-documents/{buyer\|merchant}/{terms\|privacy}/versions` | `legal.view` | Version history |
| POST | `/api/admin/legal-documents/{audience}/{type}/versions` | `legal.manage` | Upload a new draft (multipart `file`, `version_label?`, `effective_date?`, `notes?`) |
| GET | `/api/admin/legal-documents/versions/{id}` | `legal.view` | One version |
| GET | `/api/admin/legal-documents/versions/{id}/file[?download=1]` | `legal.view` | Stream any version (drafts included) for preview |
| POST | `/api/admin/legal-documents/versions/{id}/publish` | `legal.manage` | Publish; supersedes the current version |
| POST | `/api/admin/legal-documents/versions/{id}/unpublish` | `legal.manage` | Unpublish (no replacement) |

Public endpoints, rate-limited by `throttle:legal-documents` (60/min per IP). They return only the
published version, and the merchant registration form uses them:

- `GET /api/legal-documents/{buyer|merchant}` returns `{ terms, privacy }`, with `null` when not published.
- `GET /api/legal-documents/{audience}/{type}` returns metadata, or 404 "This document is temporarily unavailable."
- `GET /api/legal-documents/{audience}/{type}/file[?download=1]` streams the file (`inline`, or `attachment` with download=1).

Reused: `POST /api/merchant/register` now also requires `accept_terms`, `terms_document_id`,
`acknowledge_privacy` and `privacy_document_id` (see §6).

New artisan command: `legal-documents:import {audience} {type} {path} --admin=<email>
[--label=] [--effective-date=YYYY-MM-DD] [--publish]`. It runs the same validation as an upload and
requires an admin with `legal.manage`.

### sofiacart-website-backend (customer API, `:8001`, under `/api` and `/api/v1`)

These use the `{ data, message }` envelope and camelCase keys:

- `GET /legal-documents` returns `{ terms, privacy }` (published buyer versions only; `null` = unavailable).
- `GET /legal-documents/{terms|privacy}` returns `{ document }`, or 404.
- `GET /legal-documents/{terms|privacy}/file[?download=1]` streams the published file.
- `GET /account/legal-consents` (customer token) returns the signed-in customer's recorded consents.
- `POST /auth/register` now also requires `acceptTerms`, `termsDocumentId`, `acknowledgePrivacy`
  and `privacyDocumentId`. Its response includes `legalConsents`, read back from the database.

## 4. Storage and permission rules

- **Table `legal_documents`**: owned by sofiacart-backend and shared with the website API. Columns:
  audience, type, version (sequential per slot, never reused), version_label, effective_date,
  status (`draft|published|unpublished|superseded`), is_current, original_filename, file_path,
  mime_type, file_size, sha256 checksum, notes, uploaded_by/uploaded_at, published_by/published_at,
  unpublished_by/unpublished_at.
- **One published version per slot** is enforced in the database by a unique index on
  `(audience, type, is_current)`. `is_current` is 1 only while published and NULL otherwise.
  Publish and unpublish run in transactions with `lockForUpdate` on the slot's rows. Upload picks
  the next version number under a lock, and a unique `(audience, type, version)` index backs it up.
- **Files** are written to the private `legal` disk (`storage/app/private/legal-documents`, not under
  `public/`, so nginx never serves them directly). Each file gets a generated name,
  `<audience>/<type>/<uuid>.<pdf|docx>`. Paths never come from the request: files are resolved from
  the database row only, and the path is checked against that generated-name pattern before
  streaming, which blocks path traversal or tampered rows. API resources never include
  `file_path`; the model also hides it.
- **Validation** happens on the server. The extension must be `pdf` or `docx`, and the size is
  checked against `max:` (10 MB by default, `LEGAL_DOCUMENTS_MAX_KB`). The content must also match:
  - a PDF must be detected as `application/pdf` by finfo and contain a `%PDF-` header;
  - a .docx must be a ZIP with `[Content_Types].xml` and `word/document.xml` and the
    WordprocessingML main content type, and must **not** be macro-enabled (no `macroEnabled`
    content type, no `word/vbaProject.bin`).

  Renamed files are rejected, and the stored MIME type comes from detection, not from the client.
  Responses carry `X-Content-Type-Options: nosniff`.
- **Permissions**: two new permissions are in `AdminPermissionRegistry`.
  - `legal.view` (View legal documents) goes to Super Admin, and as view-only to the Admin role.
  - `legal.manage` (Upload and publish legal documents) goes to **Super Admin only**, since Super
    Admin receives every permission.
  - Merchants and other roles get 403. Guests get 401. The frontend mirrors this:
    `ADMIN_PERMISSIONS.LEGAL_*`, a `RequirePermission` page guard, and `Can` around the manage buttons.
- **Audit**: `LegalDocumentService` writes `legal_document.uploaded` (with
  `replaces_published_version`), `legal_document.published` (with `replaced_version`),
  `legal_document.replaced` (subject = the superseded version) and `legal_document.unpublished`
  through `AdminAuditLogger`. Each entry records the actor, IP, user agent, audience, type,
  version, file name, MIME type, size and checksum. These routes are excluded from the generic
  `AuditAdminMutation` entry so nothing is logged twice.

## 5. Publication and versioning behavior

- An upload always creates a draft. The current published version stays public until another
  version is published.
- Publishing version N makes it `published` and turns the previous published version into
  `superseded`. The public endpoints switch immediately. The file response has
  `Cache-Control: public, max-age=60` on the website API and `private, no-store` on the admin API.
- Unpublishing makes a version `unpublished`. The slot then has no public version: the pages show
  "temporarily unavailable", the public file endpoints return 404, and registration for that
  audience pauses (§6).
- No version or file is ever deleted. Consent records keep pointing to the exact version that was
  accepted.
- Drafts, superseded and unpublished versions are never served by either public API. The website
  API filters on `audience='buyer' AND status='published' AND is_current=1`, and re-checks that
  before streaming.

## 6. Customer access and consent behavior

**Customer site** (`sofiacart-website`):

- `/terms-and-conditions` and `/privacy-policy` follow the existing app-router page convention:
  a server `page.tsx` with metadata, wrapped in `Container`, around a client component.
- Each page shows the title, effective date (or published date), version, the embedded viewer,
  and **Open** (new tab) and **Download** links.
- There is a clear "temporarily unavailable" state, plus loading and error/retry states.
- The pages are responsive (verified at 375 px with no horizontal page scroll; Word pages scale to
  fit) and accessible (labelled document region, focusable viewer, `role="status"`/`"alert"` states).
- Data is always fetched from the API at runtime; there is no bundled copy.
- The footer has new **Terms & Conditions** and **Privacy Policy** links.
- The checkout "Before you place your order" notice now links both pages. Its existing checkbox
  behavior is unchanged.

**Customer registration** now has two separate checkboxes, both required:

1. "I have read and agree to the Terms and Conditions (version 1.0)". The link opens in a new tab.
2. "I acknowledge that I have read the Data Privacy Policy (version 1.0) …", followed by the note
   that this acknowledgment is **not consent to optional processing such as marketing** (promotional
   email stays a separate account setting, which is off by default).

The second checkbox follows the documents' own "Acknowledgment and Consent" sections ("By selecting
the acknowledgment checkbox …") and their statement that acceptance "shall not be treated as
blanket consent".

The form sends the IDs of the versions it showed. The backend (`RegisterRequest` +
`LegalDocumentService`) validates the following:

- both boxes are `accepted`;
- both IDs are the **currently published** buyer versions. If not, the response is a 422 such as
  "The Terms and Conditions has been updated…", and the form reloads the documents and clears the
  boxes;
- if either document is not published, the response is a 422 such as "Registration is temporarily
  unavailable because the … is not published".

Inside the registration transaction, the documents are re-read under a shared lock and
`customer_legal_consents` rows are written with: customer_account_id, legal_document_id, audience,
type, version, action (`accepted` for the Terms, `acknowledged` for the Privacy Policy),
context=`customer_registration`, accepted_at, IP and user agent. The client cannot create these
records itself. Acceptance exists only if the server wrote the row, and the client reads it back
via `legalConsents` / `GET /account/legal-consents`.

**Merchant registration** (`sofiacart-frontend` `/register/merchant`, step 4, Review & Submit)
works the same way against the merchant documents. A new "Terms and Data Privacy" section offers
an inline Read/Open for each document and the same two checkboxes. The client blocks submission
without both, and the server enforces `accept_terms`, `acknowledge_privacy`, `terms_document_id`
and `privacy_document_id` in `RegisterMerchantRequest`. Records go to `user_legal_consents` with
context=`merchant_registration`, written in the same transaction as the user and merchant rows.

**Renewed acceptance decision**: publishing a new version does **not** force existing customers
or merchants to accept again.

- No existing business rule requires it. The Buyer Terms §20.4 says SofiaCart obtains affirmative
  acceptance of updated Terms "where required", and the privacy notices say renewed consent is
  obtained "where required by law".
- What the system guarantees: every new registration accepts the then-current version, and every
  consent row records the exact version. So "who accepted which version" is always answerable, and
  customers who registered before a change can be identified by comparing their consent `version`
  with the current one.
- If a material change ever needs re-acceptance, the follow-up is to add a "requires re-acceptance"
  flag on publish, a sign-in prompt, and an `POST /account/legal-consents` endpoint that records it.
  This is not built.

Accounts that registered **before** this feature have no consent rows. That is expected, and the
same follow-up would cover them.

## 7. Required environment / configuration

**sofiacart-backend** (`.env.example` updated):

- `LEGAL_DOCUMENTS_DISK=legal`
- `LEGAL_DOCUMENTS_ROOT` (optional; default `storage/app/private/legal-documents`)
- `LEGAL_DOCUMENTS_MAX_KB=10240`. PHP `upload_max_filesize` in `docker/php/uploads.ini` is already 10M.
- `LEGAL_DOCUMENTS_REQUIRE_ACCEPTANCE=true`. Set it to `false` only to temporarily allow merchant
  registration without published documents; no consent rows are written then.
- After deploying, run `php artisan migrate` and
  `php artisan db:seed --class=Database\\Seeders\\AdminAuthorizationSeeder`, which syncs the new
  `legal.*` permissions to the system roles.

**sofiacart-website-backend** (`.env.example` updated):

- `LEGAL_DOCUMENTS_DISK=legal`, `LEGAL_DOCUMENTS_ROOT` (default `storage/app/private/legal-documents`),
  `LEGAL_DOCUMENTS_REQUIRE_ACCEPTANCE=true`.
- **The website API must read the same files as the admin API.** Locally, `docker-compose.yml` now
  mounts `../sofiacart-backend/storage/app/private/legal-documents` read-only at
  `/var/www/storage/app/private/legal-documents`. The source path can be overridden with
  `LEGAL_DOCUMENTS_HOST_PATH`.
- In production, point both APIs at one shared volume or the same S3 bucket. This means a disk
  config change: add an `s3` driver to the `legal` disk in both repos.
- Run `php artisan migrate` (creates `customer_legal_consents`).

**Frontends**: there are no new env vars. Both use their existing API base URLs. `docx-preview`
0.3.7 was added to both `package.json` files, pinned to that exact version.

Local Docker state after this work:

- Both migrations ran.
- Permissions were re-seeded.
- `sofiacart-website-api` and `sofiacart-website-scheduler` were recreated to pick up the mount.
- The four documents are imported and published as v1.
- One test customer, `legal-e2e-…@sofiacart.test`, was registered through the API to verify
  consent recording end to end.

## 8. Tests run (actual results)

| Suite | Result |
| --- | --- |
| sofiacart-backend `php artisan test` | **197 / 198 passed**. The one failure is the pre-existing `OrderWorkflowTest::test_order_creation_rejects_inactive_or_draft_products` (validation-key assertion, unrelated). |
| sofiacart-website-backend `php artisan test` | **91 / 91 passed** |
| sofiacart-frontend `npm run lint` / `npx tsc --noEmit` / `npm run build` | pass / pass / pass (route `/admin/legal-documents` built) |
| sofiacart-website `npm run lint` / `npm run typecheck` / `npm run build` | pass / pass / pass (routes `/terms-and-conditions`, `/privacy-policy` built) |
| sofiacart-frontend `npm run test:unit` | Not runnable: the known Node 20 `ERR_UNKNOWN_FILE_EXTENSION ".ts"` failure (pre-existing) |
| Pint (changed backend files) | clean. Pre-existing Pint issues in untouched files were left alone. |

New tests:

- **sofiacart-backend `LegalDocumentManagementTest`**:
  - PDF draft upload is previewable by admins but not public, is stored privately under a generated
    name with a matching checksum, and is audited without a duplicate generic entry;
  - .docx is accepted;
  - publish makes the version public (metadata and file, inline and attachment);
  - a replacement stays a draft until published, then supersedes the old version, with only one
    current version and history kept;
  - unpublish returns 404 publicly, stays previewable by admins, and gets 409 on invalid
    transitions;
  - permission matrix: guest 401; merchant 403; Admin can view but not manage; Customer Support 403;
  - rejected uploads: .txt, .png, a script renamed .pdf, a fake .docx, a PDF renamed .docx, a
    macro-enabled .docx, an oversized file and a missing file, with nothing stored;
  - unknown slots and path traversal are not routable;
  - a tampered storage path is never streamed;
  - the import command stores the file byte-identical and rejects admins without `legal.manage`.
- **sofiacart-backend `MerchantRegistrationTest`**: the existing tests now publish documents first.
  New tests cover consent recording (version, action, timestamp, user agent), missing checkboxes,
  a stale or wrong-audience version, unpublished documents (registration unavailable), and the
  config switch.
- **sofiacart-website-backend `LegalDocumentApiTest`**:
  - published buyer docs are public (PDF and DOCX, inline and download);
  - drafts, unpublished, superseded and merchant documents never are;
  - a tampered path is not served;
  - registration requires both confirmations;
  - stale or merchant versions are rejected;
  - registration is unavailable while a document is unpublished;
  - the exact version and time are recorded and readable via `/account/legal-consents`;
  - consents are scoped to the signed-in customer.

  The existing registration tests in `AuthAndAccountApiTest` and `EmailVerificationApiTest` were
  updated to accept the published documents, and still pass.

Manual end-to-end checks against the local Docker stack and running dev servers:

- Both public APIs return the published files, and their SHA-256 values match the originals.
- `/terms-and-conditions` and `/privacy-policy` render the real DOCX on desktop and at 375 px.
- The register page shows both checkboxes with versions and links, and the footer links appear.
- The live API registration was rejected without acceptance; with acceptance it stored
  `terms v1 accepted` and `privacy v1 acknowledged`.
- The Super Admin page lists all four slots, previews the real DOCX and shows the unpublish
  confirmation (cancelled).
- A temporary admin token was used for that check and revoked afterwards.

## 9. Remaining blockers and follow-ups

- **Production storage**: both APIs must share the `legal` disk (§7). This is solved locally with a
  Docker bind mount; production needs a shared volume or S3 configuration.
- **Re-acceptance flow** for new versions and for accounts that registered before this feature is
  not built (decision and design in §6).
- **Merchant documents** are shown only during merchant registration. There is no standalone
  public page for them in `sofiacart-frontend`, and admins can't yet see a merchant's or
  customer's consent records in the admin UI. The data is in `user_legal_consents` and
  `customer_legal_consents`.
- The browser UI was not exercised for uploading through the file picker, or for completing merchant
  registration. The backend tests cover upload and registration, and the merchant consent section
  was typechecked, linted and built but not clicked through.
- Pre-existing and unrelated: the one `OrderWorkflowTest` failure, and `npm run test:unit` on Node 20.
