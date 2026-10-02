# Transaction API Status

Last updated: 2026-10-02

This document covers transaction management for the Canva frame **Merchant View - Transactions**. It reuses the existing `transactions` table. Ledger rows are written only by the existing `PaymentService` and `RefundService` (see `PAYMENT_API_STATUS.md`), so there is no second transaction system.

## Scope decisions

These were confirmed with the product owner on 2026-10-02.

| Design element | Decision |
|---|---|
| "Order" (sales) rows | **Ledger only.** The transaction list shows money movements only. The **Sales Transactions** card is the count of orders placed in the period (`sales_orders`). Order rows are not stored or shown in the table. |
| "Adjustment" rows | **Not supported yet.** No adjustment type exists, and manual transaction creation was removed. |

## Ledger model

| `type` | Written by | `status` | Meaning |
|---|---|---|---|
| `payment` | `PaymentService::recordPaymentTransaction()` | `completed` | Payment verified or recorded as received |
| `payment` | same | `failed` | Payment attempt declined (`PATCH /payments/{id}/status` → failed, or recorded as failed) |
| `refund` | `PaymentService::syncRefundTransaction()` | `completed` | Refund processed |
| `refund` | same, or refund deletion | `failed` | Processed refund later un-processed or deleted ("reversed") |
| `credit` / `debit` | legacy only (seeder and older API) | any | Kept readable; no longer created by the API |

`pending` remains a valid status but is not written: pending, cancelled and expired payments create no ledger row, because no money moved.

### Lifecycle

```
Order ─▶ Payment (pending, no ledger row)
           └─ verified ─▶ Transaction{payment, completed} ─▶ payment.status=completed ─▶ order.payment_status (partially_paid/paid)
           └─ failed   ─▶ Transaction{payment, failed}    ─▶ order unchanged
Refund (pending/approved, no ledger row)
           └─ processed ─▶ Transaction{refund, completed} ─▶ payment partially_refunded/refunded ─▶ order partially_refunded/refunded
                                                         └─ cancel_order=true on a full refund of a pending/processing order
                                                            ─▶ order cancelled + stock restored via InventoryService
```

Each step runs inside the payment or refund database transaction, with row locks taken in the order order → payment → refund. Amounts always come from the payment or refund row, never from the client.

### Integrity rules

- **Server-calculated amounts.** A payment row's amount is the payment amount, which has been checked against the order's outstanding balance. A refund row's amount is the refund amount, which has been checked against the refundable balance: over-refunds return 422 and write no ledger row.
- **Immutable rows.** There is no create or delete endpoint (both return `405`). `PATCH /transactions/{id}` accepts **only `metadata`**, which is merged into the existing metadata. Sending `status`, `amount`, `type`, `reference`, `description`, `transacted_at`, `source_key`, `merchant_id`, `payment_id`, `refund_id` or `order_id` returns 422 `"Transactions are immutable; only metadata can be updated."`
- **Status comes from the backend.** Transaction status follows the payment or refund outcome. Payment status itself changes only through the payment service, via merchant/admin verification today and verified gateway callbacks once a gateway exists.
- **No duplicates.** Every service-written row has a unique `source_key`: `payment:{payment_id}` or `refund:{refund_id}`. Writes use `firstOrCreate` on that key while the payment or refund row is locked, and the database unique index rejects any second insert. Repeated verification calls or a future duplicate gateway webhook therefore can't create a second row; this is tested.
- **Consistent links.** Payment rows copy `payment_id` and `order_id` from the payment. Refund rows copy `refund_id`, `payment_id` and `order_id` from the refund, and are updated if an admin correction moves the refund to another payment.

## Endpoints

Merchant routes live under `/api/v1` (`auth:sanctum`). Admin routes live under `/api/admin` (existing admin middleware). Merchants only ever see their own merchant's rows; another merchant's row returns 404.

| Method | Merchant | Admin (permission) | Purpose |
|---|---|---|---|
| GET | `/transactions` | `/transactions` (payments.view) | Paginated list (extended) |
| GET | `/transactions/summary` | `/transactions/summary` (payments.view) | Overview cards (**new**) |
| GET | `/transactions/{id}` | `/transactions/{id}` (payments.view) | Details, history and timeline (extended) |
| PUT/PATCH | `/transactions/{id}` | `/transactions/{id}` (payments.manage) | Metadata annotation only (**restricted**) |
| POST, DELETE | — (**removed**, 405) | — | Ledger is service-written only |

`App\Policies\TransactionPolicy` is new. Merchants must own the row. Admins need an `admin`-ability token plus `payments.view` (read) or `payments.manage` (metadata).

### `GET /transactions`

| Param | Values |
|---|---|
| `search` | Transaction reference, payment reference or gateway transaction id, refund reference, order number, customer name or email, or an exact amount (`1299`, `1,299.00`) |
| `type` | `credit` \| `debit` \| `payment` \| `refund` |
| `status` | `pending` \| `completed` \| `failed` |
| `payment_method` | `card` \| `gcash` \| `maya` \| `bank_transfer` \| `cod` \| `cash` \| `paypal` \| `other` (the linked payment's method) |
| `customer_id` | the linked order's customer |
| `order_id`, `payment_id`, `refund_id` | integers |
| `date_from`, `date_to` | on `transacted_at` |
| `sort` | `newest` (default, by `transacted_at`) \| `oldest` \| `amount_desc` \| `amount_asc` |
| `merchant_id` | admin only |
| `page`, `per_page` | `per_page` is clamped to 1–100, default 15 |

Invalid values return 422.

Transaction object:

```json
{
  "id": 41, "merchant_id": 1, "payment_id": 12, "refund_id": null, "order_id": 40,
  "reference": "TXN-20261002-7KQ2M9XA",
  "type": "payment", "status": "completed",
  "amount": "2499.00", "currency": "PHP", "payment_method": "card",
  "description": "Payment TXN789456 via card",
  "transacted_at": "2026-08-16T02:25:00.000000Z",
  "payment": { "id": 12, "reference": "TXN789456", "gateway": "manual", "gateway_reference": "ch_3N8v9kL2",
               "method": "card", "status": "completed", "amount": "2499.00", "paid_at": "…" },
  "refund": null,
  "order": { "id": 40, "order_number": "SC-2026-00125", "status": "processing", "payment_status": "paid",
             "total_amount": "2499.00", "ordered_at": "…" },
  "customer": { "id": 9, "name": "Juan Dela Cruz", "email": "juan@example.com", "phone": "+63 917 123 4567" },
  "metadata": { "source": "payment" },
  "created_at": "…", "updated_at": "…"
}
```

- `refund` holds `{ id, reference, status, reason, amount, refunded_at }` for refund rows.
- `currency` comes from the linked payment; otherwise it is `PAYMENT_CURRENCY` (PHP).
- **Gateway data is limited** to the gateway name and its public transaction id.
- **Metadata is redacted** before storage and on output: card numbers, CVV, PIN, tokens, secrets and credentials.
- `source_key` is internal and not returned.

### `GET /transactions/{id}`

Returns the transaction object above, plus:

```json
"meta": {
  "history":  [ "...every ledger row for the same order (or the same payment when there is no order), oldest first" ],
  "timeline": [
    { "event": "order_created",    "title": "Order Created",    "description": "Order SC-2026-00125 was created.", "occurred_at": "…", "transaction_id": null },
    { "event": "payment_received", "title": "Payment Received", "description": "Payment TXN789456 via card",       "occurred_at": "…", "transaction_id": 41 },
    { "event": "refund_issued",    "title": "Refund Issued",    "description": "Refund RF-20261002-…",            "occurred_at": "…", "transaction_id": 44 }
  ]
}
```

Possible timeline events:
- `order_created`
- `payment_received`, `payment_failed`
- `refund_issued`, `refund_reversed`
- `credit_recorded`, `debit_recorded`

They are built only from recorded timestamps.

### `GET /transactions/summary`

`date_from` and `date_to` default to the current month.

```json
{ "data": {
  "date_from": "2026-10-01", "date_to": "2026-10-02",
  "total_transactions": 564, "previous_total_transactions": 503, "total_change_percent": 12.1,
  "sales_orders": 402,
  "by_type": { "credit": 0, "debit": 0, "payment": 148, "refund": 12 },
  "by_status": { "pending": 0, "completed": 150, "failed": 10 },
  "collected_amount": "325780.00", "refunded_amount": "24580.00", "net_amount": "301200.00",
  "currency": "PHP"
} }
```

- Counts use `transacted_at` within the period.
- `collected_amount` sums completed `payment` and `credit` rows; `refunded_amount` sums completed `refund` and `debit` rows.
- The previous period has the same length and immediately precedes the current one.

### `PATCH /transactions/{id}`

Request:

```json
{ "metadata": { "reconciled": true, "statement_line": "BPI 2026-08-16 #4471" } }
```

The values are merged into the existing metadata, with sensitive keys redacted, and the response is the updated transaction object.

## Database changes

There is one additive migration, `2026_10_02_020000_add_source_key_to_transactions_table`. It adds `transactions.source_key` as a nullable `string(100)` with a **unique** index.

It backfills `refund:{refund_id}` for rows with a refund, and `payment:{payment_id}` for `payment`-type rows. Only the oldest row per event receives a key, so the backfill can't fail on legacy duplicates. `down()` drops the column.

The migration was applied to the local Docker MySQL database. All 45 existing rows are seeded legacy `credit` rows, so none needed a key, and the unique index was confirmed. Rollback and re-apply were verified on a throwaway SQLite database.

No other schema change was needed. `type` already includes `payment` and `refund`, `refund_id` already exists, and `status` already has `failed`; these came from `2026_10_02_010000_add_payment_management_fields`.

## Files

- New:
  - `app/Policies/TransactionPolicy.php`
  - `app/Http/Requests/ListTransactionsRequest.php`
  - the migration above
  - `tests/Feature/TransactionManagementTest.php`
- Rewritten:
  - `app/Http/Controllers/Api/TransactionsController.php` (no store or destroy; adds summary, details history and timeline, metadata-only update)
  - `app/Http/Requests/UpdateTransactionRequest.php` (metadata only)
  - `app/Http/Resources/TransactionResource.php`
- Changed:
  - `app/Services/PaymentService.php`:
    - `recordPaymentTransaction()` is now public and idempotent, and also records failed payments.
    - Source keys are set on refund rows.
  - `app/Models/Transaction.php` (`source_key` fillable)
  - `routes/api.php`
- Removed: `app/Http/Requests/StoreTransactionRequest.php` (only used by the removed create endpoint)
- Tests updated:
  - `PaymentRefundWorkflowTest::test_transaction_cross_relation_integrity` now asserts that creation returns 405, that the auto-written row is linked to the payment and order, and that link edits return 422, instead of creating rows by hand.
  - Two `PaymentRefundWorkflowTest` fixtures now pin `status => pending`. `Order::factory()` picks a random status, which could randomly be `cancelled`, and payments on cancelled orders are rejected.
  - One `PaymentManagementTest` assertion now expects the failed-payment ledger row.

## Tests and results

Tests were run in the `app` container with `docker compose exec app php artisan test` (SQLite in-memory).

`tests/Feature/TransactionManagementTest.php` adds 6 tests, all passing:
1. **Payment → transaction → order → refund → refund transaction → payment and order updates.**
   - The order is created with no row, and the pending payment has no row.
   - Verification writes one `payment` row with customer, order number, method, currency and gateway reference; the order is paid.
   - A partial refund writes a `refund` row, and the payment and order become partially refunded.
   - An over-refund returns 422 and writes no row.
   - A full refund with `cancel_order` sets payment and order to refunded, cancels the order and returns stock from 8 to 10.
   - The details endpoint returns a history of 3 rows and the timeline order_created → payment_received → refund_issued ×2. The summary totals match.
2. **Failed payment.** Both a declined pending payment and one recorded as failed write `failed` ledger rows. The order stays unpaid, collected stays 0.00, and refunds on a failed payment are rejected.
3. **Duplicate callbacks.**
   - Verifying twice through the API, calling `PaymentService::transition()` again (the path a webhook would use), and calling `recordPaymentTransaction()` twice all leave exactly one row, and the second call returns the same row.
   - Processing a refund twice leaves one row.
   - A raw duplicate insert with the same `source_key` throws `UniqueConstraintViolationException`.
4. **Immutability.** POST and DELETE return 405; status and amount edits return 422; metadata is required. A metadata update merges with existing keys, redacts the card number in the response, and never stores it.
5. **Access.** Another merchant gets an empty list (even with a forged `merchant_id`) and 404 on show and update. A Product Manager admin gets 403. Customer Support can read but gets 403 on update. An Order Manager can annotate.
6. **Pagination, filters and search:**
   - pagination metadata and page 2
   - type, payment method, customer, order and date-range filters
   - search by order number, customer name, a formatted amount (`1,299.00`) and transaction reference
   - amount and oldest sorting
   - 422 on an invalid type, sort or method

The full suite has **101 passing and 6 failing**, compared with 95 and 6 before this work. The payment, transaction, admin and inventory suites were also run 5 times in a row, with 58/58 passing each time.

The 6 failures predate this work and are unrelated:
- `ProductCrudTest` (3) and `ReturnRequestWorkflowTest::test_variant_stock_…` (1): a partial product `PATCH` nulls `products.price`.
- `OrderWorkflowTest::…rejects_inactive…` (1): the test expects validation error key `items`.
- `CategoryManagementTest` (1): `imagewebp` is missing from the container's GD build.

Other checks:
- `php artisan route:list --path=transactions` lists 8 routes, with no POST or DELETE.
- List, filter and search, summary and details were run as a seeded merchant against the Docker **MySQL** data and all returned 200.
- `vendor/bin/pint` passed.

## Blockers and remaining work

1. **Order rows and Adjustment transactions** are out of scope by decision (see *Scope decisions*). Adding adjustments later needs a new type, an authorized and server-validated creation endpoint, and a `source_key` per adjustment.
2. **No payment gateway or webhook** exists yet (see `PAYMENT_API_STATUS.md`). Duplicate protection already holds at the service and database level: a future webhook handler should call `PaymentService::transition()` and can use `source_key` values such as `{gateway}:{event_id}`.
3. **Export Transactions, Print Receipt and Send Email** from the design are not implemented.
4. **Order status timeline entries** ("Order Confirmed", "Processing") can't be shown with times, because the schema doesn't record when an order's status changed. The current order status is returned in `order.status`.
5. **Seeded dev data** was inserted directly, so it has only legacy `credit` rows. Seeded processed refunds have no `refund` ledger rows, which makes `refunded_amount` on dev data understate refunds. New activity through the API is recorded correctly.
6. **The frontend** hasn't been updated. Its read-only Transactions page keeps working, but its `AdminTransaction.type` type only lists `credit | debit` and should add `payment | refund`.
