# Payment API Status

Last updated: 2026-10-02

This covers payment management for these Canva frames:
- **Merchant View - Payments**
- **Merchant View - Record Payment**
- **Merchant View - Refunds**
- the payment and refund side of **Merchant View - Transactions**
- the payment methods shown in **Customers - Check Out**

It extends the existing `payments`, `refunds` and `transactions` tables and controllers. No parallel payment system was added.

## Gateway integration

**No payment gateway is integrated.** This was confirmed with the product owner on 2026-10-02 ("No gateway yet").

Every payment is recorded with the `manual` gateway and one of these methods: `card`, `gcash`, `maya`, `bank_transfer`, `cod`, `cash`, `paypal`, `other`. These cover the methods in the checkout and Payments designs.

Verification is a server-side action by an authorized merchant or admin who confirms the money was received (bank statement, GCash/Maya merchant app, card terminal, COD remittance). That user is recorded in `verified_by`. **Payment status is never accepted from the customer or derived from client-calculated totals.** The rules:
- `PATCH /payments/{id}` rejects `status` outright.
- Completion goes through `PATCH /payments/{id}/status`, which re-checks the order's outstanding balance under a row lock, rejects expired payments, and is permission-checked.
- `config/payments.php` → `integrated_gateways` lists gateways whose payments may only be completed by verified gateway callbacks. For any gateway listed there, `PaymentService::ensureManualConfirmationAllowed()` blocks manual completion, both on create and in the status endpoint. The list is empty today.

**Not implemented, because each needs a real gateway:**
- hosted checkout or payment-link initialization
- the gateway verification API call
- the webhook/callback endpoint and signature check
- gateway-executed refunds

No mock gateway responses exist. To add a gateway:
1. Put its credentials in `.env` and read them through `config/services.php`.
2. Add a webhook route that verifies the gateway signature and calls `PaymentService::transition()`.
3. Add the gateway to `payments.integrated_gateways`.

## Payment lifecycle

```
Order ─▶ POST /payments (status=pending, method=…)        ← initialization
          │   reference auto-generated, expires_at = now + PAYMENT_PENDING_EXPIRY_MINUTES (COD: none)
          ├─▶ PATCH /payments/{id}/status {completed}     ← verification by merchant/admin
          │      • order balance re-checked under lock • paid_at, verified_by set
          │      • Transaction{type: payment, status: completed} written
          │      • order.payment_status re-derived (partially_paid / paid)
          ├─▶ PATCH …/status {failed, reason}  → failed      (terminal)
          ├─▶ PATCH …/status {cancelled}       → cancelled   (terminal)
          └─▶ `php artisan payments:expire` (every 5 min) → expired (terminal)

POST /payments (status=completed)  ← "Record Payment" for money already received: same checks,
                                     transaction and order sync in one DB transaction
completed ─▶ partially_refunded ─▶ refunded   (derived from processed refunds only)
```

| Payment status | Counts as collected |
|---|---|
| `pending` | no; it is shown as `pending_amount` in the balance |
| `completed`, `partially_refunded`, `refunded` | yes |
| `failed`, `cancelled`, `expired` | no |

Order `payment_status` is always derived by `PaymentService::syncOrderPaymentStatus()`, never accepted from input:

| Condition | Order payment status |
|---|---|
| refunded ≥ paid (and refunded > 0) | `refunded` |
| refunded > 0 | `partially_refunded` |
| paid ≥ order total | `paid` |
| paid > 0 | `partially_paid` (**new**) |
| otherwise | `unpaid` |

Other lifecycle rules:
- Payments cannot be recorded for cancelled orders.
- A payment cannot exceed the order's outstanding balance (order total minus collected).
- An order can only be completed when `paid`, which is existing behaviour.
- An order can now be cancelled when `unpaid` **or fully `refunded`**. Previously a refunded order could never be cancelled.
- Collected payments cannot have their amount, order or merchant edited, and cannot be deleted (409). Refund them instead.

## Refund lifecycle

> **Superseded:** refund management was extended later (`processing`, `failed` and `cancelled` statuses, item and return refunds, idempotency, history). `REFUND_API_STATUS.md` is the current reference; this section describes the payment-era baseline.

```
POST /refunds (status defaults to pending)  ─▶ pending ─┬─▶ approved ─┬─▶ processed
                                                        │             └─▶ rejected
                                                        ├─▶ processed
                                                        └─▶ rejected
```

The design's Reject, Approve and Process Refund actions all appear on a pending refund.

- **Refundable amount.** Refunds are allowed only on collected payments. A new refund request, together with all other open (pending or approved) and processed refunds on the payment, cannot exceed the payment amount. Processing re-checks against processed refunds only, under row locks (order, then payment, then refund).
- **On processing:**
  - `refunded_at` and `reviewed_by`/`reviewed_at` are set.
  - A `Transaction{type: refund, status: completed, refund_id}` is written.
  - The payment becomes `partially_refunded` or `refunded`, and the order payment status is re-derived.
- **Order and inventory.** `cancel_order: true` is accepted on create or process with `status=processed`. When the order becomes fully refunded and is still `pending` or `processing`, it is cancelled and its stock restored through `InventoryService::restoreStockForOrder()` (idempotent, logged as an inventory `cancellation`). Partial refunds or completed orders return 422, because completed orders restock through the existing **return request** flow (`PATCH /return-requests/{id}` → `processed`, which requires a processed refund). A refund alone never restocks.
- **Corrections.** `PATCH /refunds/{id}` (amount, payment/order link, reason, notes) still works as before for admin corrections. It re-syncs payment, order and the refund's ledger row. Status changes are rejected there and must use `/status`. Deleting a refund marks its ledger row `failed`.
- **Recording.** Creating with `status=processed` still works, which is how the current frontend's "Record processed refund" page records refunds paid out outside SofiaCart.

## Endpoints

Merchant routes live under `/api/v1` (`auth:sanctum`). Admin routes live under `/api/admin` with the existing admin middleware plus a permission per route. Merchants are always scoped to their own merchant, and other merchants' records return 404.

| Method | Merchant `/api/v1` | Admin `/api/admin` (permission) | Purpose |
|---|---|---|---|
| GET | `payments` | `payments` (payments.view) | List, extended |
| GET | `payments/summary` | `payments/summary` (payments.view) | Overview cards (**new**) |
| GET | `payments/{id}` | `payments/{id}` (payments.view) | Details, extended |
| POST | `payments` | `payments` (payments.manage) | Initialize or record (extended) |
| PUT/PATCH | `payments/{id}` | `payments/{id}` (payments.manage) | Edit details (status prohibited) |
| PATCH | `payments/{id}/status` | `payments/{id}/status` (payments.manage) | Verify, fail or cancel (**new**) |
| DELETE | `payments/{id}` | — | Delete a never-collected payment |
| GET | `payments/{id}/attachments/{index}` | same (payments.view) | Download payment proof (**new**) |
| GET | `orders/{order}/payment-balance` | same (payments.view) | Due amount and payments for an order (**new**) |
| GET | `refunds` | `refunds` (payments.view) | List, extended |
| GET | `refunds/summary` | `refunds/summary` (payments.view) | Overview cards (**new**) |
| GET | `refunds/{id}` | `refunds/{id}` (payments.view) | Details, extended |
| POST | `refunds` | `refunds` (payments.refund) | Create request or record processed |
| PUT/PATCH | `refunds/{id}` | `refunds/{id}` (payments.refund) | Correct details (status prohibited) |
| PATCH | `refunds/{id}/status` | `refunds/{id}/status` (payments.refund) | Approve, reject or process (**new**) |
| DELETE | `refunds/{id}` | — | Delete (409 if a return uses it) |
| GET | `transactions` | `transactions` (payments.view) | Transaction history; see `TRANSACTION_API_STATUS.md` |

`PaymentPolicy` and `RefundPolicy` are new and auto-discovered:
- Merchants must own the record.
- Admins need an `admin`-ability token plus `payments.view`, `payments.manage` (payments) or `payments.refund` (refunds).

The policies also apply when an admin calls `/api/v1`, which previously had no permission check.

### `GET /payments`

Query parameters:

| Param | Values |
|---|---|
| `search` | Payment reference, `gateway_reference`, order number, customer name or email |
| `status` | Any payment status |
| `method` | Any payment method |
| `gateway`, `order_id`, `customer_id` | exact match |
| `date_from`, `date_to` | Filter on `created_at` |
| `sort` | `newest` (default) \| `oldest` \| `amount_desc` \| `amount_asc` \| `paid_at_desc` \| `paid_at_asc` |
| `merchant_id` | Admin only |
| `page`, `per_page` | `per_page` is clamped to 1–100 |

Rows include `order` with `customer`.

### Payment object

```json
{
  "id": 12, "merchant_id": 1, "order_id": 40,
  "reference": "PAY-20261002-7KQ2M9XA",
  "gateway_reference": "GC12345678",
  "gateway": "manual", "method": "gcash",
  "status": "completed", "amount": "2499.00", "currency": "PHP",
  "paid_at": "2026-08-16T02:25:00.000000Z", "expires_at": null,
  "failure_reason": null, "notes": "Verified in GCash merchant app",
  "attachments": [{ "index": 0, "name": "gcash-receipt.jpg", "mime": "image/jpeg", "size": 245000 }],
  "verified_by": { "id": 3, "name": "Maria Dela Cruz" },
  "order": { "...OrderResource with customer and items" },
  "transactions": [ "...TransactionResource" ],
  "refunds": [ "...RefundResource" ],
  "metadata": { "terminal": "POS-1", "card_number": "[REDACTED]" },
  "created_at": "…", "updated_at": "…"
}
```

- `verified_by`, `order`, `transactions` and `refunds` appear on the detail, create, update and status responses.
- Those responses also include `meta.order_balance`:

```json
{ "total_amount": "2499.00", "amount_paid": "2499.00", "amount_refunded": "0.00",
  "net_paid": "2499.00", "pending_amount": "0.00", "outstanding_balance": "0.00" }
```

- Attachment storage paths are never exposed. Use the download endpoint.

### `POST /payments`

Accepts JSON, or `multipart/form-data` when attaching proof:

| Field | Rules |
|---|---|
| `order_id` | optional; must belong to the merchant and not be cancelled |
| `method` | required unless `gateway` is sent; one of the methods above |
| `gateway` | optional, default `manual`. Legacy values such as `gcash`/`paymaya` also set `method` |
| `amount` | required, > 0, ≤ the order's outstanding balance |
| `status` | `pending` (default) \| `completed` \| `failed` |
| `reference` | optional, unique; auto-generated `PAY-YYYYMMDD-XXXXXXXX` |
| `gateway_reference` | optional; the design's Transaction ID |
| `paid_at` | optional, not in the future (`completed` only) |
| `notes` | optional, max 500 (the design's Remarks) |
| `failure_reason` | optional (`failed` only) |
| `currency` | optional; must equal `PAYMENT_CURRENCY` (default `PHP`) |
| `attachments[]` | up to 5 files, jpg/jpeg/png/pdf, ≤ 5 MB each; stored on the private `local` disk under `payment-proofs/{merchant}` |
| `metadata` | optional object |

Success returns `201`.

### `PATCH /payments/{id}/status`

```json
{ "status": "completed", "paid_at": "2026-08-16T10:25:00+08:00", "gateway_reference": "GC12345678", "notes": "…" }
```

- `failed` requires `reason`; `cancelled` accepts an optional `reason`.
- Only `pending` payments can change. Setting the current status again is a no-op that returns 200.
- Other transitions, expired payments and over-balance amounts return 422 `{ message, errors.status | errors.amount }`.

### `GET /payments/summary`

`date_from` and `date_to` default to the current month.

```json
{ "data": {
  "date_from": "2026-10-01", "date_to": "2026-10-02",
  "total_payments": 162, "completed_payments": 142,
  "by_status": { "pending": 14, "completed": 140, "failed": 6, "cancelled": 0, "expired": 0, "partially_refunded": 1, "refunded": 1 },
  "collected_amount": "325780.00", "previous_collected_amount": "290875.00", "collected_change_percent": 12.0,
  "refunded_amount": "24580.00", "currency": "PHP"
} }
```

- Counts use `created_at` within the period.
- `collected_amount` is collected payments by `paid_at` within the period.
- The previous period has the same length and ends where the current one starts.

### `GET /orders/{order}/payment-balance`

Returns the order balance fields above, plus `order_id`, `order_number`, `order_status`, `payment_status`, `currency` and `payments[]`.

### Refund endpoints

- **`POST /refunds`:**
  - `payment_id` (required)
  - `amount` (required)
  - `order_id` (optional; derived from the payment)
  - `reference` (optional; auto-generated `RF-YYYYMMDD-XXXXXXXX`)
  - `reason` (max 255, for example "Defective Item")
  - `notes` (max 500)
  - `status` (default `pending`)
  - `refunded_at`
  - `cancel_order` (bool)
  - `metadata`
- **`PATCH /refunds/{id}/status`:** `{ status: approved|rejected|processed, notes?, refunded_at?, cancel_order? }`.
- **`GET /refunds`:**
  - `search` (refund reference, order number, customer name or email)
  - `status`
  - `reason` (contains)
  - `payment_id`, `order_id`
  - `date_from`, `date_to`
  - `sort` (`newest` \| `oldest` \| `amount_desc` \| `amount_asc`)
  - `merchant_id` (admin)
- **`GET /refunds/summary`:** `total_refunds`, `by_status`, `refunded_amount`, `previous_refunded_amount`, `refunded_change_percent`, `currency`.

Refund object:

```json
{
  "id": 32, "merchant_id": 1, "payment_id": 12, "order_id": 40,
  "reference": "RF-20261002-QX81PL0D", "amount": "799.00",
  "reason": "Defective Item", "notes": "Product not working properly upon received.",
  "status": "processed", "refunded_at": "…", "reviewed_at": "…",
  "reviewed_by": { "id": 3, "name": "Maria Dela Cruz" },
  "payment": { "id": 12, "reference": "…", "gateway": "manual", "method": "card", "amount": "2499.00", "currency": "PHP", "status": "partially_refunded" },
  "order": { "...OrderResource with customer and items" },
  "return_request": { "id": 5, "status": "processed", "reason": "…", "notes": "…", "evidence": [{ "index": 0, "name": "photo.jpg", "mime": "image/jpeg" }] },
  "metadata": null, "created_at": "…", "updated_at": "…"
}
```

`return_request.evidence` can be downloaded from the existing `GET /return-requests/{id}/evidence/{index}`.

## Transactions

Transactions are written automatically as the ledger. Since the transaction work (see `TRANSACTION_API_STATUS.md`), there is no manual `POST`/`DELETE /transactions`, rows are immutable except `metadata`, and each row has a unique `source_key` (`payment:{id}` / `refund:{id}`) so duplicates are impossible.

| Event | Transaction |
|---|---|
| Payment completed (created as completed, or verified) | `type=payment`, `status=completed`, `amount`, `payment_id`, `order_id` |
| Payment failed (created as failed, or marked failed) | `type=payment`, `status=failed` |
| Refund processed | `type=refund`, `status=completed`, `refund_id`, `payment_id`, `order_id` |
| Processed refund edited | its row is updated to match |
| Refund un-processed or deleted | its row becomes `status=failed` ("reversed"/"deleted") |

References are auto-generated as `TXN-YYYYMMDD-XXXXXXXX`. The legacy `credit` and `debit` types remain valid.

## Security

- **Card data.** No card numbers, CVV, PINs or gateway secrets are collected; the API has no card-data fields.
- **Metadata redaction.** Payment, refund and transaction `metadata` is redacted **before it is stored** by the new `App\Models\Concerns\RedactsSensitiveMetadata` trait, and again on output. Keys matching any of these are replaced with `[REDACTED]`: password, token, secret, authorization, `auth`, api key, private key, client secret, cvv, cvc, card number, security code, `pin`, credential. `auth` and `pin` are matched as whole words, so keys like `author` or `shipping` are left alone.
- **Credentials.** Gateway credentials, when a gateway is added, belong in `.env` only. `config/payments.php` stores none.
- **Payment proofs** are stored on the private disk and only downloadable by authorized users of the owning merchant.
- **Locking.** Status changes and balance checks run in DB transactions with row locks taken in the order order → payment → refund.

## Database changes

There is one additive migration, `2026_10_02_010000_add_payment_management_fields`. No tables are created or dropped. Its `down()` maps new enum values back to old ones before narrowing.

| Table | Change |
|---|---|
| `payments` | `status` enum adds `cancelled`, `expired`. New columns: `method` string(50), `currency` char(3) default `PHP`, `gateway_reference`, `notes`, `attachments` json, `failure_reason`, `expires_at`, `verified_by` → users (nullOnDelete). Indexes: `(merchant_id, method)`, `gateway_reference`, `(status, expires_at)` |
| `orders` | `payment_status` enum adds `partially_paid` |
| `refunds` | New columns: `notes`, `reviewed_by` → users (nullOnDelete), `reviewed_at` |
| `transactions` | `type` enum adds `payment`, `refund`. New column: `refund_id` → refunds (nullOnDelete) |

Backfill: `payments.method` is set from legacy `gateway` values (`gcash`, `paymaya` → `maya`, `bank_transfer`, `card`, `cod`, `cash`, `paypal`).

The migration was applied to the local Docker MySQL database with `php artisan migrate`. All 45 existing payments received a method, and no data was lost. Both new migrations (inventory and payment) were also verified to roll back and re-apply on a throwaway SQLite database.

Other code changes:
- New enums and values:
  - New `App\Enums\PaymentMethod`.
  - `PaymentStatus::captured()` and `isCaptured()`.
  - New cases in `PaymentStatus`, `OrderPaymentStatus` and `TransactionType`.
- `Payment` now has `HasFactory` and a new `PaymentFactory`.
- New config `config/payments.php`:
  - `PAYMENT_CURRENCY`
  - `PAYMENT_PENDING_EXPIRY_MINUTES` (default 1440)
  - `integrated_gateways`
  - attachment limits
- New command `payments:expire`, scheduled every five minutes in `routes/console.php`. **The server must run `php artisan schedule:work` or a cron job running `schedule:run`.**

## Files

- New:
  - `app/Services/PaymentService.php`
  - `app/Services/RefundService.php`
  - `app/Policies/PaymentPolicy.php`
  - `app/Policies/RefundPolicy.php`
  - `app/Enums/PaymentMethod.php`
  - `app/Models/Concerns/RedactsSensitiveMetadata.php`
  - `app/Http/Requests/{ListPayments,UpdatePaymentStatus,ListRefunds,UpdateRefundStatus}Request.php`
  - `config/payments.php`
  - `database/factories/PaymentFactory.php`
  - the migration above
  - `tests/Feature/PaymentManagementTest.php`
- Changed:
  - `app/Http/Controllers/Api/{Payments,Refunds}Controller.php` (rewritten onto the services)
  - `OrdersController.php` (a fully refunded order can now be cancelled)
  - payment and refund requests and resources
  - `TransactionResource`
  - `SanitizesMetadata`
  - `Payment`, `Refund`, `Transaction` models
  - `PaymentStatus`, `OrderPaymentStatus`, `TransactionType` enums
  - `routes/api.php`, `routes/console.php`
  - `tests/Feature/PaymentRefundWorkflowTest.php`. `TransactionStatus::Success`, which never existed, was replaced with `TransactionStatus::Completed`; nothing else in the test changed.

## Tests and results

Tests were run in the `app` container with `docker compose exec app php artisan test` (SQLite in-memory).

`tests/Feature/PaymentManagementTest.php` adds 9 tests (203 assertions), all passing:
- A pending payment is created with an auto reference and expiry, and no ledger row or order change. A status change through the details endpoint is rejected. Verification sets `verified_by`, writes a `payment` transaction and marks the order paid. Completed payments are terminal, and re-verifying is idempotent.
- Partial payments: the order is `partially_paid`, then `paid`. Overpayment is rejected on create and when verifying an older pending payment. The order balance endpoint returns the correct figures.
- `failed` requires a reason. Cancellation works. An expired payment cannot be confirmed, `payments:expire` expires only past-due non-COD payments, and an uncollected payment can be deleted.
- A collected payment's amount can't be edited and it can't be deleted (409). Payments for a cancelled order are rejected.
- A proof upload is downloadable by the owner, its path is hidden, and other merchants get 404. Card number and CVV are redacted in the database itself.
- List search (customer, order number), filters, sort, validation and summary figures.
- Refunds:
  - Refunds are rejected on uncollected payments.
  - A pending request reserves its amount, and the status-through-update path is rejected.
  - The approve → process flow writes a `refund` transaction, sets partially-refunded statuses and records the reviewer. Rejected refunds are terminal.
  - Filters and summary return the right results.
  - Deleting a refund reverses its ledger row and re-syncs the statuses.
- Scoping and permissions: another merchant gets 404. An admin without `payments.view` gets 403. Customer Support can view but not verify. An Order Manager can verify.
- **Full flow:** order (stock 10 → 6) → payment initialized → order processing → payment verified (order paid) → cancellation blocked while paid → partial refund with `cancel_order` rejected → partial refund processed → remaining refund processed with `cancel_order` → order cancelled, fully refunded, stock back to 10, `cancellation` inventory log. The ledger holds exactly one payment and two refund transactions.

The full suite now has **95 passing and 6 failing**. Before this work it was 83 passing and 9 failing. The 3 previously failing `PaymentRefundWorkflowTest` tests now pass: the Payment factory was missing, `gateway_auth` wasn't redacted, and the test used a non-existent enum case. The 6 remaining failures predate this work and are outside payments:
- `ProductCrudTest` (3) and `ReturnRequestWorkflowTest::test_variant_stock_…` (1): a partial `PATCH /products/{id}` nulls `products.price`.
- `OrderWorkflowTest::…rejects_inactive…`: the test expects error key `items`, but the API returns `items.0.product_id`.
- `CategoryManagementTest` (1): `imagewebp` is missing from the container's GD build.

Other checks:
- `php artisan route:list --path=payment` lists 17 routes and `--path=refund` lists 13.
- All GET endpoints (list, filter, sort, search, summary, detail, order balance, refund list and summary) were run against the Docker **MySQL** data as a seeded merchant and returned 200 with correct figures.
- `vendor/bin/pint` passed on all new and changed files.

## Blockers and remaining work

1. **Payment gateway (main blocker).** These need a chosen gateway (for example PayMongo or Xendit, both of which support the design's card, GCash and Maya methods), its API keys and a webhook URL:
   - online initialization (hosted checkout or payment links)
   - server-to-server verification
   - the webhook/callback endpoint with signature verification
   - gateway-executed refunds

   The integration points are listed in *Gateway integration* above.
2. **Customer checkout.** There is no customer-facing auth or checkout API in the backend; customers are merchant-managed records. Payment initialization is therefore merchant or admin-initiated.
3. **PayPal.** It appears in the Payments design but not in checkout. It is supported only as a recorded method.
4. **Transactions page.** This is implemented separately; see `TRANSACTION_API_STATUS.md`.
5. **Refund attachments.** Refunds show the linked return request's evidence. Refunds without a return request have no upload of their own.
6. **Seeded dev data.** It was written directly to the database, so some seeded orders with processed refunds still show `payment_status=paid`. They re-sync on the next payment or refund action on that order.
7. **Scheduler.** It must be running for `payments:expire`; otherwise pending payments are only rejected at confirmation time and never move to `expired`.
8. **Frontend.** It has not been updated. The current pages keep working, since the old payloads are still accepted, but they don't use the new status, summary or balance endpoints.
