# SofiaCart Super Admin – Status

_Last updated: 2026-10-03. The same file is kept in `sofiacart-backend` and `sofiacart-frontend`._

## 1. What already existed (reused)

- **Backend:** `/api/admin/*` behind `auth:sanctum` + `admin` (active admin user) + `admin.token` (token with the `admin` ability) + `admin.audit` + per-route `admin.permission:*` (RBAC from `AdminPermissionRegistry` / `AdminRoleRegistry`). It covered merchants (list, detail, status, onboarding history, billing), customers, platform-wide orders, products, inventory, payments, transactions and refunds (these reuse the merchant controllers, which are unscoped for admins), platform reports + CSV export, settings, users, roles, permissions and audit logs.
- **Merchant lifecycle (existing enum `MerchantStatus`):** `pending` → `verified` (approved) / `information_requested` / `rejected`, plus `suspended`. Registration (`POST /api/merchant/register`) stores business, store and owner data plus the business permit, government ID and logo/banner files, and sets `pending`.
- **Frontend:** the `/admin/*` app with its own session, Axios client, `RequirePermission`/`Can` guards, permission-filtered sidebar and pages for every section.

## 2. Canva UI implementation

The design was read directly from the Canva whiteboard (frames: Dashboard, Merchant List, Onboarding, Billing, Platform Settings, Commissions & Fees, Email & Notifications, Create Template, User Management, System Logs). Changes were made to the existing shell and shared kit, so every admin page picks them up:

- **Shell:** dark navy sidebar with the SofiaCart mark, icons and collapsible groups (Merchants → Merchant List / Onboarding / Billing; Orders → All Orders / Returns; Products → All Products / Inventory; User Management → Users / Roles & Permissions), filled purple for the active item, a white header with "Super Admin" and a breadcrumb, and the avatar menu.
- **Kit (`components/admin/ui.tsx`):** page header with an icon tile, stat cards with tinted icon tiles, white panels, underline tabs, dot status pills, a search field with an icon, labelled filter row, compact tables, row icon actions, numbered pagination with "Showing X to Y of Z", a right-hand details drawer, and loading, empty, error and 403 states.
- Screens without a Canva frame (Orders, Returns, Products, Inventory, Customers, Payments, Reports) use the same system.
- **Not built** because the backend has no data for them: Total MRR, "vs last month" trends, plans, the per-transaction billing fee, the notification bell and global search.

## 3. Sections updated

| Section | Data / behaviour |
| --- | --- |
| Dashboard | All-time merchants by status, pending onboarding, customers, products, inventory low/out-of-stock. Range metrics: orders, gross sales, collected payments, processed refunds, transactions. Charts: daily or monthly merchant growth, merchant-status donut and collected-payments trend. Also latest/awaiting-review merchants, top merchants, orders by status, and recent audit activity (needs `logs.view`). |
| Merchant List | Status/category cards; search, status, category, joined-date range, 7 sorts, pagination |
| Onboarding | Status tabs with counts, search, registration-date range, Info→Docs→Review→Approval progress (derived), review drawer (details, documents, decision, history) |
| Merchant detail | Overview (application, documents, decision, history) + Products, Inventory, Orders, Returns, Customers, Payments & Refunds, Billing tabs (each permission-gated) |
| Billing | Month payment/collected/refunded cards, active merchants, sales-by-merchant bars, per-merchant collected/refunds/net + recent payments |
| Orders / Returns | Merchant/status/payment/date filters; detail drawer with items, totals, payments, refunds, returns; status transitions; return review |
| Products / Inventory | Merchant/status/stock filters, variants, images; moderation (existing status/delete). Inventory: on-hand/reserved/available/stock status, movement history |
| Customers | Masked contact, account status, type, merchant; drawer with orders, payments, refunds |
| Payments | Payments / Transactions / Refunds tabs with summaries, filters (merchant, status, method, type, dates), detail drawers (ledger, timeline, refund history) and refund status changes |
| Reports | Merchant performance, orders, payments (date + merchant filters, CSV export), sales trend, products, customers, inventory |
| System Logs | Module, actor, entity ID and date filters; log detail drawer with redacted metadata |
| Users / Roles / Settings / Sessions | Unchanged logic; restyled through the shared kit |

## 4. APIs

**Added** (all `admin.permission`-gated):
- `GET /api/admin/merchants/summary` (`merchants.view`): counts by status + store categories
- `GET /api/admin/merchants/{merchant}/documents/{business_permit|government_id|store_logo|store_banner}` (`merchants.view`): streams the uploaded file (`private, no-store`)
- `GET /api/admin/return-requests`, `GET /api/admin/return-requests/{id}`, `GET /api/admin/return-requests/{id}/evidence/{index}` (`orders.view`); `PATCH /api/admin/return-requests/{id}` (`payments.refund`, existing review rules)

**Updated:**
- `GET /api/admin/merchants`: `ListMerchantsRequest` (`statuses[]`, `store_category`, `date_from`/`date_to`, `sort`)
- `GET /api/admin/merchants/{id}`: counts for customers, payments, transactions, refunds, plus `documents`
- `PATCH /api/admin/merchants/{id}/status`: row lock, 422 on no-op (prevents duplicate approvals), reason required for `rejected`/`information_requested`, audit metadata now includes `previous_status`
- Orders/products index: admin `merchant_id` filter; admin rows include `merchant {id, store_name, store_slug}` (also payments, transactions, refunds, returns, customers). Admin order detail includes payments, refunds and return requests.
- `GET /api/admin/dashboard`: adds `totals`, `series` and payment/refund/transaction metrics (existing keys unchanged)
- `GET /api/admin/reports/platform` and `/export`: `merchant_id`, `date_from`, `date_to`
- `GET /api/admin/logs`: `module`, `date_from`, `date_to`
- `GET /api/admin/customers`: `status` filter; resource adds `status`, `customer_type`, `merchant` (contact still masked)
- All admin routes are now named (`admin.*`). Audit entries for admin mutations use the route name as the action, resolve scalar route IDs to the entity (`subject_type`/`subject_id`), and record the submitted field names and status, never the values of credentials.

Frontend calls each of these through `lib/api/admin.ts` only. Refund changes now use the dedicated `PATCH /refunds/{id}/status` (server-enforced transitions) instead of the generic update.

## 5. Database

**No migrations.** Every field already existed. Two Eloquent relations were added (`Order::returnRequests`, `ReturnRequest::merchant/customer`).

## 6. Authorization

- Every new or updated endpoint sits inside the existing admin middleware stack and has a route permission.
- Merchant tokens get 403 on `/api/admin/*` and can't approve any merchant, themselves included. An admin without `merchants.manage` (for example the "Admin" role) gets 403 on status changes. Both are covered by tests.
- The frontend hides actions with `Can` and pages with `RequirePermission`, but the backend is the enforcement point.

## 7. Merchant approval workflow

1. A merchant registers and becomes `pending`.
2. An admin reviews the application under Onboarding: details, documents streamed through the authenticated API, and the progress steps.
3. The admin chooses Approve (`verified`), Request info (`information_requested`, reason required) or Reject (`rejected`, reason required), or another status (suspend, return to pending).
4. The backend locks the row, rejects a no-op, saves the status and writes `admin.merchants.status` with the actor, timestamp, IP, previous and new status, and reason. This appears in the onboarding history and in System Logs.

## 8. Tests actually run

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Admin` | **43/43 passed** (276 assertions), including 13 new tests in `SuperAdminMerchantOversightTest` |
| `php artisan test` (full) | 128 tests: **123 passed, 5 failed**. The same 5 fail on the untouched baseline (`ProductCrudTest` ×3 `products.price` NOT NULL on update, `OrderWorkflowTest` error key, `ReturnRequestWorkflowTest`), outside admin scope. |
| `vendor/bin/pint --dirty` | passed |
| `npx tsc --noEmit`, `npm run lint` | passed |
| Unit + smoke tests (`tests/*.test.mjs`) | **63/63 passed**. Local Node is v20, which cannot import `.ts`, so the suite ran through a scratch TypeScript loader (`typescript.transpileModule`) with no project changes. `npm run test:unit` needs Node ≥ 22.6. |
| `npm run build` | passed; includes new `/admin/orders/returns` and `/admin/products/inventory` |
| Live UI check | Throwaway SQLite DB (scratch, factory data; the project database was not touched). All 33 admin endpoints the UI calls returned 200. In the browser: the dashboard rendered; rejecting without a reason was blocked; approval persisted, refreshed the queue and wrote history and System Logs; merchant detail, order drawer, inventory, payments, reports, billing and logs rendered; mobile layout stacked correctly. |

The new tests cover the 15 required checks. View merchants and onboarding; approve; reject with the required reason; unauthorized approval returns 403; decisions persist and are logged; products, inventory, orders, payments, refunds, returns and customers are visible platform-wide; the dashboard and reports use real data; and the existing admin tests still pass.

## 9. Remaining gaps / blockers

- **Approval doesn't gate merchant access.** No existing rule restricts what a `pending` or `rejected` merchant can do in `/api/v1`, so approval only changes the status. Adding gating would change merchant behaviour and needs a product decision.
- **Registration files live on the `public` disk** (`merchants/{slug}/…`). Government IDs and permits are reachable at `/storage/...` once `storage:link` exists. The admin UI streams them through the authenticated endpoint, but moving them to private storage is recommended.
- **Not in the backend:** promotions/vouchers, subscription plans, invoices, platform commission or per-transaction fees (Canva Billing, Commissions & Fees), email templates, product reviews, an order status timeline, and an admin invite/reset-link URL (see the older frontend status notes).
- **No admin stock-adjustment UI.** `POST /api/admin/inventory/adjust` exists but isn't exposed, because adjustments need merchant/variant context. Inventory in the admin is view-only.
- Customer contact details stay masked by design.
- Activity "Security / API / Error log" tabs from Canva have no data source; only the admin audit log exists.
- Older audit rows written before route naming keep `METHOD path` actions with no subject.
- `npm run build` regenerated `next-env.d.ts` (auto-generated by Next; the dev server rewrites it).

## 10. Files changed

**Backend**
- `routes/api.php`
- `app/Http/Controllers/Api/Admin/{MerchantManagement,Dashboard,PlatformReports,SystemLog,CustomerManagement}Controller.php`
- `app/Http/Controllers/Api/{Orders,Products,Payments,Transactions,Refunds,ReturnRequests}Controller.php`
- `app/Http/Middleware/AuditAdminMutation.php`
- `app/Http/Requests/Admin/{ListMerchantsRequest (new),UpdateMerchantStatusRequest,PlatformReportRequest}.php`
- `app/Http/Resources/Concerns/IncludesMerchantSummary.php` (new)
- `app/Http/Resources/{Order,Product,Payment,Transaction,Refund,ReturnRequest}Resource.php`
- `app/Http/Resources/Admin/{AdminMerchant,AdminCustomer}Resource.php`
- `app/Models/{Order,ReturnRequest}.php`
- `tests/Feature/Admin/SuperAdminMerchantOversightTest.php` (new)

**Frontend**
- `components/admin/{ui,admin-shell,merchant-status-form,merchant-billing-panel}.tsx`
- New: `components/admin/{merchant-application,merchant-filter,private-file}.tsx` and `components/admin/lists/{orders,returns,products,inventory,finance,customers}-list.tsx`
- `app/admin/(protected)/{dashboard,merchants,merchants/onboarding,merchants/[id],merchants/billing,orders,products,customers,payments,reports,logs}/page.tsx`
- New: `app/admin/(protected)/{orders/returns,products/inventory}/page.tsx`
- `lib/api/admin.ts`, `lib/admin/permissions.ts`, `lib/validation/admin.ts`, `types/admin.ts`, `tests/admin-permissions.test.mjs`
