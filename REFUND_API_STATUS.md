# Refund API Status

Last updated: 2026-10-02

This document covers refund management for the Canva frame **Merchant View - Refunds**. It extends the existing `refunds` table, `RefundsController` and `RefundService`, and connects them to the existing return requests (**Request Return / Submit Return Request**), payments, the transaction ledger and inventory. No parallel refund system was added.

## Lifecycle

```
Authorized user (merchant / admin)                       [no customer-facing API exists]
  └─ POST /refunds ──▶ pending (requested)
        validation: ownership, customer, payment collected, refundable amount & quantities, duplicates
        ├─▶ approved ─┬─▶ processing (payout started) ─┬─▶ processed (completed) ─▶ ledger/payment/order/inventory
        │             │                                └─▶ failed (reason) ─┬─▶ processing (retry) / processed
        │             │                                                     └─▶ cancelled
        │             ├─▶ processed
        │             └─▶ rejected / cancelled
        ├─▶ processing / processed
        └─▶ rejected / cancelled
```

The existing status values were kept, so `pending` means requested and `processed` means completed. This migration adds `processing`, `failed` and `cancelled`.

| Status | Meaning | Reserves amount and quantities | Terminal |
|---|---|---|---|
| `pending` | Requested | yes | no |
| `approved` | Approved, not yet paid out | yes | no |
| `processing` | Payout started (optional `payout_reference`) | yes | no |
| `failed` | Payout failed (`failure_reason` required) | yes, until it is retried or cancelled | no |
| `processed` | Completed and confirmed | counted as refunded | yes |
| `rejected` | Rejected by the merchant or admin | no | yes |
| `cancelled` | Withdrawn | no | yes |

`RefundStatus::allowedTransitions()` defines every permitted move. Anything else, including setting `pending` again, returns 422. Repeating the current status is a no-op that returns 200, so double clicks or repeated callbacks change nothing.

### Payment gateway step

**No payment gateway is integrated** (confirmed on 2026-10-02; see `PAYMENT_API_STATUS.md`). Refunds go to the original payment's `manual` gateway. The merchant sends the money outside SofiaCart (GCash/Maya send, bank transfer, cash or card terminal), then confirms it with `processing` → `processed` or `failed`, optionally adding a `payout_reference`. That authorized confirmation is the backend confirmation, and the system never completes a refund on its own.

For any gateway listed in `config('payments.integrated_gateways')`, `processed` and `failed` cannot be set manually; they must come from verified gateway callbacks. That list is empty today. Executing refunds through a gateway is a blocker (see below).

## Business rules

All of these are enforced server-side, inside `DB::transaction` with row locks taken in the order order → payment → refund.

- **Ownership.**
  - Refunds, orders, payments and return requests must belong to the authenticated merchant; otherwise the API returns 404.
  - If `customer_id` is sent, it must be the order's customer (422).
  - Item lines must belong to the order (422).
- **Eligibility.**
  - The payment must be collected (`completed`, `partially_refunded` or `refunded`); otherwise 422 on `payment_id`.
  - A return-based refund needs an **approved** return that hasn't been refunded and has no open refund already.
- **Amounts are never trusted from the client.** Exactly one source is accepted:

| Source | Amount |
|---|---|
| `return_request_id` | The return's server-computed amount and items |
| `items[]` (`order_item_id`, `quantity`) | `order_item.total_price × qty ÷ ordered qty`, summed |
| `full_refund: true` | The payment's whole remaining refundable balance |
| `amount` (legacy, kept for the existing frontend "Record processed refund" page) | Accepted only as a value **capped** at the refundable balance |

  Sending two sources (for example `items` plus `amount`) returns 422.
- **Maximum refundable amount.** On request: (open + completed refunds on the payment) + this amount ≤ the payment amount. On completion it is re-checked against completed refunds under lock. When no `payment_id` is given, the most recent collected payment on the order with enough balance is used.
- **Quantities.** Refundable quantity per order item is the ordered quantity minus quantities in open or completed refunds, minus quantities in non-rejected return requests that don't yet have an open or completed refund.
- **Full and partial refunds.**
  - Partial refunds set the payment and order to `partially_refunded`; refunding the full amount sets them to `refunded`.
  - `cancel_order: true` is only accepted when this refund makes the order fully refunded and the order is still `pending` or `processing`.
- **Duplicates.**
  - **`Idempotency-Key` header** (optional, ≤ 100 characters): unique per merchant. A repeated `POST` returns the original refund with 200 instead of 201, and concurrent duplicates are resolved by the unique index.
  - **Quantity and balance reservations** reject a second request for the same items or money.
  - **Ledger:** a completed refund has exactly one ledger row (`source_key = refund:{id}`), even across retries.
- **Edits.**
  - `PATCH /refunds/{id}` can't change status.
  - Amount, payment and order are locked for item and return refunds. Amount-only refunds keep the existing admin correction behaviour.
- **Deletion.** Refunds that are `processing` or `processed`, or linked to a processed return, can't be deleted (409).

## Effects of a completed refund

These all happen in the same database transaction:

| Record | Effect |
|---|---|
| Refund | `status=processed`, `refunded_at`, `payout_reference`, `reviewed_by`/`reviewed_at`, a history entry |
| Transaction | One `type=refund`, `status=completed` row (`refund_id`, `payment_id`, `order_id`, amount). A failed payout writes or updates the same row as `failed`; a successful retry flips it to `completed`. |
| Payment | `partially_refunded` or `refunded` (`PaymentService::syncPaymentRefundStatus`) |
| Order | `payment_status` becomes `partially_refunded` or `refunded` (`PaymentService::syncOrderPaymentStatus`) |
| Return request (return refunds) | Becomes `processed` with `refund_id` set, and returned items are **restocked** through `InventoryService::restoreStockForReturn` (inventory log `type=return`) |
| Order (`cancel_order`) | Becomes `cancelled`, and its stock is restored through `InventoryService::restoreStockForOrder` (log `type=cancellation`, idempotent through `inventory_restored`) |

Refunds by `items` or `amount` alone **do not restock**, because a financial refund doesn't prove goods came back; that is the existing rule. Restocking requires a return request or an order cancellation.

If a completion effect no longer applies (for example the order shipped after `cancel_order` was requested), it is skipped and noted in the refund history, so a refund whose money has already moved is never blocked.

## Endpoints

Merchant routes live under `/api/v1` (`auth:sanctum`). Admin routes live under `/api/admin` (existing admin middleware plus a permission per route). Policy: the existing `RefundPolicy`:
- Merchants must own the record.
- Admins need an `admin` token plus `payments.view` (read) or `payments.refund` (request, approve, process, edit, delete).

| Method | Merchant | Admin (permission) | Purpose |
|---|---|---|---|
| GET | `/refunds` | `/refunds` (payments.view) | List (filters extended) |
| GET | `/refunds/summary` | `/refunds/summary` (payments.view) | Overview cards (new statuses included) |
| GET | `/refunds/{id}` | `/refunds/{id}` (payments.view) | Details: items, payment, order, return, ledger, history |
| GET | `/orders/{order}/refundable` | same (payments.view) | Refundable items and balances (**new**) |
| POST | `/refunds` | `/refunds` (payments.refund) | Request a refund (**reworked**) |
| PATCH | `/refunds/{id}/status` | same (payments.refund) | Approve, reject, process, complete, fail or cancel (**extended**) |
| PUT/PATCH | `/refunds/{id}` | same (payments.refund) | Correct details (restricted, see rules) |
| DELETE | `/refunds/{id}` | — | Delete a refund that never paid out |

### `POST /refunds`

Optional header: `Idempotency-Key: <string ≤ 100>`.

```json
{
  "order_id": 40,
  "customer_id": 9,
  "items": [{ "order_item_id": 101, "quantity": 1 }],
  "reason": "Defective Item",
  "notes": "Product not working properly upon received.",
  "cancel_order": false
}
```

Fields:
- `order_id`: required unless `payment_id` or `return_request_id` is given.
- `payment_id`: optional; defaults to the order's most recent payment with enough balance.
- **Exactly one** of `return_request_id`, `items[]`, `full_refund: true`, or `amount`.
- `reason`: ≤ 255 characters (design values: Defective Item, Wrong Item, Changed Mind, Not as Described, Damaged on Delivery). Defaults to the return's reason.
- `notes`: ≤ 500 characters.
- `reference`: optional, unique; auto-generated as `RF-YYYYMMDD-XXXXXXXX`.
- `status`: `pending` (default), or `processed` to record a refund already paid out. The latter is allowed only for manual-gateway payments, runs the full completion checks, and writes two history entries.
- `refunded_at`, `payout_reference`, `metadata` (redacted before storage).

Responses: `201` when created; `200` when the `Idempotency-Key` was already used; `422` for rule violations; `404` for another merchant's order, payment or return.

### `PATCH /refunds/{id}/status`

```json
{ "status": "processing", "payout_reference": "GC-REFUND-0032", "notes": "Sent via GCash" }
{ "status": "failed", "failure_reason": "GCash account closed" }
{ "status": "processed", "refunded_at": "2026-08-16T10:45:00+08:00" }
```

- `status` is one of `approved`, `rejected`, `processing`, `processed`, `failed`, `cancelled`.
- `failure_reason` is required for `failed`.
- `cancel_order` may also be set on approve, process or complete.

### `GET /refunds`

| Param | Values |
|---|---|
| `search` | Refund reference, order number, customer name or email |
| `status` | any refund status |
| `reason` | contains |
| `customer_id`, `order_id`, `payment_id`, `return_request_id` | integers |
| `payment_method` | `card` \| `gcash` \| `maya` \| `bank_transfer` \| `cod` \| `cash` \| `paypal` \| `other` |
| `date_from`, `date_to` | on `created_at` |
| `sort` | `newest` (default) \| `oldest` \| `amount_desc` \| `amount_asc` |
| `merchant_id` | admin only |
| `page`, `per_page` | `per_page` is clamped to 1–100 |

### Refund object

Returned by details, create and status responses; list rows contain the core fields plus `order` and `payment`.

```json
{
  "id": 32, "merchant_id": 1, "payment_id": 12, "order_id": 40, "return_request_id": 5,
  "reference": "RF-20261002-QX81PL0D", "payout_reference": "GC-REFUND-0032",
  "amount": "799.00", "currency": "PHP",
  "reason": "Defective Item", "notes": "…", "failure_reason": null,
  "status": "processed", "cancel_order": false,
  "refunded_at": "…", "reviewed_at": "…",
  "requested_by": { "id": 3, "name": "Maria Dela Cruz" },
  "reviewed_by": { "id": 3, "name": "Maria Dela Cruz" },
  "items": [{ "id": 1, "order_item_id": 101, "product_name": "Wireless Headphones", "sku": "WH-001",
              "unit_price": "799.00", "quantity": 1, "amount": "799.00" }],
  "payment": { "id": 12, "reference": "…", "gateway": "manual", "method": "card", "amount": "2499.00",
               "currency": "PHP", "status": "partially_refunded" },
  "order": { "...OrderResource with customer and items" },
  "return_request": { "id": 5, "status": "processed", "reason": "…", "notes": "…",
                      "evidence": [{ "index": 0, "name": "photo.jpg", "mime": "image/jpeg" }] },
  "transactions": [ "...TransactionResource (the refund's ledger row)" ],
  "history": [
    { "from_status": null, "to_status": "pending", "notes": "Refund requested.", "user": { "id": 3, "name": "…" }, "created_at": "…" },
    { "from_status": "pending", "to_status": "approved", "notes": null, "user": { "…" }, "created_at": "…" }
  ],
  "metadata": null, "created_at": "…", "updated_at": "…"
}
```

- The design's **Attachments** are the linked return request's `evidence`, downloaded from the existing `GET /return-requests/{id}/evidence/{index}`.
- The design's **Refund Method** is `payment.method` (the original payment method).

### `GET /orders/{order}/refundable`

```json
{ "data": {
  "order_id": 40, "order_number": "SC-2026-00124", "order_status": "completed", "payment_status": "paid", "currency": "PHP",
  "items": [{ "order_item_id": 101, "product_id": 7, "product_variant_id": null, "product_name": "Wireless Headphones", "sku": "WH-001",
              "quantity": 3, "unit_price": "200.00", "total_price": "600.00",
              "refunded_or_reserved_quantity": 2, "refundable_quantity": 1 }],
  "payments": [{ "payment_id": 12, "reference": "…", "method": "gcash", "gateway": "manual", "amount": "1000.00", "refundable_amount": "600.00" }],
  "refundable_amount": "600.00"
} }
```

### `GET /refunds/summary`

Returns `total_refunds`, `by_status` (all seven statuses), `refunded_amount`, `previous_refunded_amount`, `refunded_change_percent` and `currency`. `date_from` and `date_to` default to the current month.

## Database changes

There is one additive migration, `2026_10_02_030000_add_refund_management_tables`. Its `down()` maps the new statuses back (`processing` → `approved`, `failed`/`cancelled` → `rejected`) before narrowing the enum.

| Table | Change |
|---|---|
| `refunds` | `status` enum adds `processing`, `failed`, `cancelled`. New columns: `return_request_id` → return_requests (nullOnDelete), `payout_reference`, `failure_reason`, `cancel_order` bool, `requested_by` → users (nullOnDelete), `idempotency_key`. Unique `(merchant_id, idempotency_key)`; index `(payment_id, status)` |
| `refund_items` (**new**) | `refund_id` (cascade), `order_item_id` (cascade), `quantity`, `amount`. Unique `(refund_id, order_item_id)` |
| `refund_status_histories` (**new**) | `refund_id` (cascade), `from_status`, `to_status`, `user_id` (nullOnDelete), `notes`, `created_at` |

Backfill: every existing refund gets one history row with its current status.

The migration was applied to the local Docker MySQL database: all 15 existing refunds got history rows, and no data was lost. Rollback and re-apply were verified on a throwaway SQLite database.

New models are `RefundItem` and `RefundStatusHistory`. The `Refund` model gains `items`, `histories`, `requester`, `returnRequest` (the return it was requested for) and `processedReturn` (the return processed with it; this was previously named `returnRequest`). `ReturnRequest` gains `refundRequests`.

## Files

- New:
  - `app/Models/RefundItem.php`
  - `app/Models/RefundStatusHistory.php`
  - the migration above
  - `tests/Feature/RefundManagementTest.php`
- Rewritten:
  - `app/Services/RefundService.php`: `request()`, `transition()` and `refundableSummary()`, plus server-side pricing, quantity reservations, idempotency, completion effects and history
  - `app/Http/Requests/StoreRefundRequest.php`
  - `app/Http/Requests/UpdateRefundStatusRequest.php`
  - `app/Http/Resources/RefundResource.php`
- Changed:
  - `app/Enums/RefundStatus.php` (new cases plus `open()`, `committed()`, `allowedTransitions()`)
  - `app/Http/Controllers/Api/RefundsController.php` (filters, idempotent store, refundable endpoint, restricted edits and deletes)
  - `app/Http/Requests/ListRefundsRequest.php`
  - `app/Services/PaymentService.php` (`syncRefundTransaction` writes `failed` rows for failed payouts)
  - `app/Models/Refund.php`, `app/Models/ReturnRequest.php`
  - `routes/api.php`
- Test updated: `PaymentManagementTest::test_refund_requests_are_…` now expects that deleting a processed refund returns 409, and deletes the rejected refund instead.

## Tests and results

Tests were run in the `app` container with `docker compose exec app php artisan test` (SQLite in-memory).

`tests/Feature/RefundManagementTest.php` adds 8 tests, all passing:
1. **Order → payment → refund request → approval → processing → completion.**
   - A client `amount` sent alongside `items` is rejected.
   - The item refund is priced server-side (2 × ₱200 = ₱400), and `/refundable` shows the reserved and refundable quantities.
   - approved → processing (with payout reference) creates no ledger row and leaves the payment unchanged; processed writes one `refund` ledger row and sets payment and order to partially refunded.
   - The history shows each step and its user.
   - Stock is not restored without a return, and a completed refund can't be cancelled or have its amount edited.
2. **Return → refund → restock.**
   - A refund is rejected before the return is approved, and a duplicate return refund or an overlapping item refund is rejected.
   - Completion processes the return (`refund_id` linked) and restores the cable stock from 9 to 10 with a `return` inventory log and one ledger row.
3. **Full refund.** `cancel_order` with a partial amount is rejected. `full_refund` computes ₱1,000 and a second full refund is rejected. Completion cancels the order, restores all stock, refunds payment and order, and leaves a refundable balance of 0.
4. **Rejected and cancelled.** A reservation blocks a second request for the same quantity; rejecting or cancelling releases it. Rejected refunds are terminal and can be deleted.
5. **Failed payout.** `failed` needs `failure_reason` and writes a `failed` ledger row while the payment stays completed. Processing refunds can't be deleted (409), and failed refunds still reserve their amount. A retry → processed flips the same ledger row to `completed` (exactly one row).
6. **Duplicates.** The same `Idempotency-Key` returns the same refund with 200 (one row). Completing twice gives one ledger row and one `processed` history entry, and an oversized key returns 422.
7. **Invalid and unauthorized:**
   - customer mismatch, uncollected payment, an item from another order, an amount over the refundable balance, no amount source, a disallowed create status, and setting `pending` all return 422
   - another merchant gets 404 on show, status, refundable, and create by payment or order
   - Customer Support (view-only) gets 403 on approve; Order Manager (`payments.refund`) can approve
8. **List.** Pagination, customer, payment method, status, order and reason filters, search by customer and order number, amount sort, date filter, invalid method → 422, and summary counts and amount.

The full suite has **109 passing and 6 failing**, compared with 101 and 6 before this work. The refund, payment, transaction, admin and inventory suites passed 3 runs in a row (66/66).

The 6 failures predate this work and are unrelated:
- `ProductCrudTest` (3) and `ReturnRequestWorkflowTest::test_variant_stock_…` (1): a partial product `PATCH` nulls `products.price`.
- `OrderWorkflowTest::…rejects_inactive…` (1): the test expects validation error key `items`.
- `CategoryManagementTest` (1): `imagewebp` is missing from the container's GD build.

Other checks:
- `php artisan route:list --path=refund` lists 15 routes.
- `/orders/{id}/refundable`, refund details with history, filtered list and summary were run against the Docker **MySQL** data as a seeded merchant and all returned 200.
- `vendor/bin/pint` passed.

## Blockers and remaining work

1. **Gateway-executed refunds.** There is no payment gateway, so the gateway refund call and its webhook aren't implemented. Payouts are confirmed by authorized users. Once a gateway is added, `processing` should call the gateway's refund API, and its webhook should call `RefundService::transition()` with `processed` or `failed`. The `refund:{id}` ledger key and idempotent transitions already guard against duplicate callbacks.
2. **Customer-initiated refund requests** need a customer-facing auth and API, which the backend doesn't have; customers are merchant-managed records. Requests are made by merchants or admins, optionally with `customer_id` for the ownership check.
3. **Legacy and amount-only refunds** (existing rows, the seeder, `amount` mode) have no item lines, so they don't reduce item refundable quantities; the money cap still applies. Seeded refunds also have no ledger rows, because the seeder bypasses the services.
4. **Refunds without a return request** have no attachment upload of their own; attachments come from the linked return request.
5. **Shipping and discount refunds** aren't separated: item pricing uses `order_items.total_price`. Orders are currently created with zero discount and shipping, and the `amount` or `full_refund` modes cover extras.
6. **The frontend** hasn't been updated. Its "Record processed refund" page keeps working (`amount` + `status=processed`), but it doesn't use items, the status endpoint, refundable or history.
