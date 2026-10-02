# Inventory API Status

Last updated: 2026-10-02

Implements the inventory backend for the Canva frames **Merchant View - Inventory**, **Merchant View - Update Stock** and the stock data used by **Merchant View - Inventory Reports**. It builds on the existing `products`, `product_variants`, `orders`/`order_items` and `inventory_logs` tables and on `App\Services\InventoryService`.

## Stock model

There is no separate stock table. Each **stock pool** is one inventory item:

| Item type | Stored stock column | Example |
|---|---|---|
| `product` | `products.stock_quantity` (base stock, orderable without a variant) | `product:12` |
| `variant` | `product_variants.stock` | `variant:5` |

The stored column is the **available** quantity, because order creation already deducts it. The other figures are derived on the server and never accepted from the client:

| Field | Meaning | Source |
|---|---|---|
| `available` | Sellable now | stored column |
| `reserved` | Held by open orders | `SUM(order_items.quantity)` for orders in `pending`/`processing` with `inventory_restored = false` (variant items count against the variant, the rest against the product's base stock) |
| `on_hand` | Physically in stock | `available + reserved` |
| `stock_status` | `out_of_stock` when `available <= 0`; `low_stock` when `track_inventory` and `available <= low_stock_threshold`; otherwise `active` | same rule as `Product::stock_status` |

Variants use their product's `low_stock_threshold`, `track_inventory`, `cost_price` and category.

## Endpoints

Merchant routes live under `/api/v1` (`auth:sanctum`). Admin routes live under `/api/admin` (`auth:sanctum`, `admin`, `admin.token`, `admin.audit`, plus `admin.permission:products.inventory.manage`).

| Method | Merchant | Admin | Purpose |
|---|---|---|---|
| GET | `/api/v1/inventory` | `/api/admin/inventory` | Paginated inventory items (**new**) |
| GET | `/api/v1/inventory/summary` | `/api/admin/inventory/summary` | Overview cards and status chart (**new**) |
| GET | `/api/v1/inventory/products/{product}` | `/api/admin/inventory/products/{product}` | Product stock details for Update Stock (**new**) |
| GET | `/api/v1/inventory/logs` | `/api/admin/inventory/logs` | Stock movement history (**extended**) |
| POST | `/api/v1/inventory/adjust` | `/api/admin/inventory/adjust` | Increase, decrease or set stock (**extended**, backward compatible) |

### `GET /inventory`

Query parameters, all optional (`ListInventoryItemsRequest`):

| Param | Values |
|---|---|
| `search` | Matches name, SKU, brand, category name, variant colour and size |
| `category_id`, `product_id` | integer |
| `stock_status` | `active` \| `low_stock` \| `out_of_stock` |
| `type` | `product` \| `variant` |
| `sort` | `name_asc` (default), `name_desc`, `sku_asc`, `sku_desc`, `stock_asc`, `stock_desc` (on hand), `available_asc`, `available_desc`, `reserved_asc`, `reserved_desc`, `updated_asc`, `updated_desc` |
| `merchant_id` | Admin only; merchants are always scoped to their own merchant |
| `page`, `per_page` | `per_page` is clamped to 1–100 (default 15) |

The response is a standard paginated resource collection (`data`, `links`, `meta`). Each item looks like this:

```json
{
  "id": "variant:5",
  "item_type": "variant",
  "product_id": 2,
  "product_variant_id": 5,
  "merchant_id": 1,
  "name": "Running Shoes",
  "sku": "RS-002",
  "brand": null,
  "product_status": "active",
  "variant": { "color": "Green", "size": "9" },
  "category": { "id": 3, "name": "Footwear" },
  "image": "products/1/shoe.png",
  "price": "120.00",
  "cost_price": "60.00",
  "track_inventory": true,
  "low_stock_threshold": 10,
  "on_hand": 18,
  "reserved": 2,
  "available": 16,
  "stock_status": "active",
  "inventory_value": "1080.00",
  "updated_at": "2026-10-02T02:00:00.000000Z"
}
```

`variant` is `null` for product rows. `inventory_value` is `on_hand × cost_price`, or `null` when there is no cost price.

The design's **Low Stock Items** panel uses `GET /inventory?stock_status=low_stock&sort=available_asc&per_page=5`.

### `GET /inventory/summary`

```json
{ "data": {
  "total_items": 124, "total_skus": 123,
  "in_stock": 98, "low_stock": 12, "out_of_stock": 6, "reserved_items": 8,
  "total_on_hand": 3560, "total_reserved": 21, "total_available": 3539,
  "inventory_value": "892450.00"
} }
```

Admins may pass `merchant_id`; without it, admins get platform-wide totals.

### `GET /inventory/products/{product}`

Returns `404` for another merchant's product.

```json
{ "data": {
  "product": { "...ProductResource with category, variants, image_items" },
  "items": [ "...InventoryItemResource for the base stock, then each variant" ],
  "recent_movements": [ "...latest 10 InventoryLogResource with variant + user" ]
} }
```

### `GET /inventory/logs` (movement history)

The existing endpoint, extended (`ListInventoryLogsRequest`). Filters: `product_id`, `product_variant_id`, `type`, `reference_type`, `reason` (contains), `search` (reference number, reason, supplier, product name/SKU, variant SKU), `date_from`, `date_to`, `sort` (`newest` (default) \| `oldest`), and `merchant_id` (admin only). The design's **Recent Stock Movements** panel uses `GET /inventory/logs?per_page=5`.

```json
{
  "id": 41, "merchant_id": 1, "product_id": 2, "product_variant_id": 5, "user_id": 7,
  "type": "stock_in", "reason": "Stock added",
  "quantity_change": 20, "previous_stock": 22, "resulting_stock": 42,
  "reference_type": "purchase_order", "reference_number": "PO-2026-0156",
  "supplier": "ABC Electronics Inc.", "reference_date": "2026-08-16",
  "notes": "Received new stock from supplier.",
  "product": { "...ProductResource" },
  "variant": { "id": 5, "sku": "RS-002", "color": "Green", "size": "9" },
  "user": { "id": 7, "name": "Maria Dela Cruz" },
  "created_at": "2026-10-02T02:00:00.000000Z"
}
```

Movement `type` values: `stock_in`, `stock_out`, `adjustment`, `sale`, `cancellation`, `return`. A `user` of `null` means a system action. `previous_stock` and `resulting_stock` are **available** stock for the pool.

### `POST /inventory/adjust` (Update Stock)

This is the Update Stock form payload (`AdjustInventoryRequest`):

```json
{
  "product_id": 2,
  "product_variant_id": 5,
  "adjustment_type": "increase",
  "quantity": 20,
  "reason": "Supplier delivery",
  "reference_type": "purchase_order",
  "reference_number": "PO-2026-0156",
  "supplier": "ABC Electronics Inc.",
  "reference_date": "2026-08-16",
  "notes": "Received new stock from supplier. Ref: PO-2026-0156"
}
```

| `adjustment_type` | Effect | Movement type | `quantity` |
|---|---|---|---|
| `increase` | Adds to on-hand and available | `stock_in` | 1–1,000,000 |
| `decrease` | Removes from on-hand and available | `stock_out` | 1–1,000,000 |
| `set` | Replaces the **on-hand** count, so available becomes `quantity − reserved` | `adjustment` | 0–1,000,000 |

The original payload `{ product_id, quantity_change (signed, ≠ 0), reason, notes }` still works. Exactly one of `adjustment_type` or `quantity_change` must be sent. Field rules: `product_variant_id`, `reason`, `reference_*`, `supplier` and `reference_date` are optional, and `notes` allows at most 500 characters.

A successful request returns `200`. `inventory_item` is new; the other keys are unchanged.

```json
{
  "message": "Inventory adjusted successfully.",
  "product": { "...ProductResource" },
  "inventory_item": { "...InventoryItemResource after the change" },
  "inventory_log": { "...InventoryLogResource" }
}
```

Errors use the standard Laravel `422 { message, errors }` format:
- `quantity`, or `quantity_change` for the original payload: the result would be negative ("Available: N"), `set` is below the reserved quantity, or the value is unchanged.
- `product_variant_id`: the variant does not belong to the product.

## Inventory rules

- **All writes are server-side and transactional.** `InventoryService::adjustStock()` runs in `DB::transaction` and locks the product row, then the variant row, with `lockForUpdate()`. That is the same order order creation uses, so concurrent orders and adjustments serialize without deadlocking. Reserved stock is read inside the lock.
- **No negative stock.** Decreases cannot exceed available stock, and `set` cannot go below reserved stock. The stock columns are also `unsigned`.
- **Order creation** (existing `lockAndValidateProductsForOrder` / `deductStockForOrder`) rejects overselling and deducts stock. It now logs `type=sale`, `reference_type=order`, `reference_number=<order_number>` and the `product_variant_id`.
- **Order cancellation** (`restoreStockForOrder`) restores stock exactly once, guarded by `orders.inventory_restored`. It logs `type=cancellation`.
- **Order completion** writes nothing new. The order stops counting as reserved, so `on_hand` drops by the shipped quantity and `available` is unchanged.
- **Processed returns** (`ReturnRequestsController::review`, now delegating to the new `InventoryService::restoreStockForReturn`) restock only the returned quantities. They log `type=return`, `reference_type=return_request`, `reference_number=<return id>`. The logic was moved unchanged from the controller.
- **Refunds alone do not restock.** This is existing behaviour: only a processed return puts goods back.
- Frontend stock values are never trusted. Order prices and stock checks happen server-side, and adjustment results are computed from locked rows.

## Authorization

- `App\Policies\ProductPolicy` (new, auto-discovered) defines two abilities:
  - `viewInventory(User, ?Product)` for list, summary, details and logs.
  - `manageInventory(User, Product)` for adjust.
- **Merchants** must be linked to a merchant account and may only access their own products. Queries are scoped by `merchant_id`; another merchant's product returns `404`, and a `merchant_id` query parameter is ignored.
- **Admins** need an `admin`-ability Sanctum token and the `products.inventory.manage` permission (held by the Super Admin, Admin and Product Manager roles). This is enforced by route middleware on `/api/admin/*`, and by the policy when an admin calls `/api/v1/*`.
- Fixed bug: `POST /api/admin/inventory/adjust` previously always returned 422, because it required the admin to be linked to a merchant. Admin adjustments now use the product's merchant.

## Database changes

There is one additive migration, `2026_10_02_000000_add_movement_fields_to_inventory_logs_table`. It creates no tables, drops nothing, and does not modify product or order tables.

| Column on `inventory_logs` | Type |
|---|---|
| `product_variant_id` | nullable FK → `product_variants`, `nullOnDelete` |
| `type` | nullable `string(32)` (`InventoryMovementType`) |
| `reference_type` | nullable `string(64)` |
| `reference_number` | nullable `string(100)`, indexed |
| `supplier` | nullable `string` |
| `reference_date` | nullable `date` |

It also adds an index on `(merchant_id, type)`. The migration backfills existing rows from the reason and notes formats the app already writes:
- `Order created:` becomes `sale`.
- `Order cancelled:` becomes `cancellation`.
- `Return processed:` becomes `return`.
- Everything else becomes `adjustment`.
- `variant #N` in `notes` fills `product_variant_id`.

`down()` drops only the added columns and indexes.

The migration was applied to the local Docker MySQL database with `php artisan migrate`. All 75 existing dev logs were classified as `adjustment`, because they are seeded `restock` / `manual_adjustment` rows.

New enums: `App\Enums\InventoryMovementType`, `App\Enums\InventoryAdjustmentType`.

## Files

- New:
  - `app/Policies/ProductPolicy.php`
  - `app/Enums/InventoryMovementType.php`
  - `app/Enums/InventoryAdjustmentType.php`
  - `app/Http/Requests/ListInventoryItemsRequest.php`
  - `app/Http/Requests/ListInventoryLogsRequest.php`
  - `app/Http/Resources/InventoryItemResource.php`
  - the migration above
  - `tests/Feature/InventoryManagementTest.php`
- Changed:
  - `app/Services/InventoryService.php`
  - `app/Http/Controllers/Api/InventoryController.php`
  - `app/Http/Requests/AdjustInventoryRequest.php`
  - `app/Http/Resources/InventoryLogResource.php`
  - `app/Models/InventoryLog.php`
  - `app/Http/Controllers/Api/ReturnRequestsController.php` (stock restoration delegated to the service)
  - `routes/api.php`

## Tests and results

Tests were run in the `app` container with `docker compose exec app php artisan test` (SQLite in-memory, per `phpunit.xml`).

`tests/Feature/InventoryManagementTest.php` adds 8 tests, all passing:
- List with derived on-hand/reserved/available for product and variant rows, merchant isolation, and exact summary totals.
- Search (name, variant colour), filters (stock status, category, type, product), sorting, and validation of bad `sort`/`stock_status`.
- Product details: stock pools plus recent movements with variant and user.
- Update Stock increase/decrease/set with reference, supplier, date and remarks, plus movement-history filters.
- Negative-stock prevention: decrease beyond available, `set` below reserved, unchanged value, quantity 0, both payload styles at once, a variant from another product. All of these leave no partial writes.
- Cross-merchant access returns 404 and stock is unchanged.
- Admin access: denied without `products.inventory.manage` or without an admin token. A Product Manager can list, summarize, view and adjust, and the log records the product's merchant.
- **Full flow:** product → stock adjustment (product and variant) → order (stock deducted and reserved) → cancellation (restored exactly once) → new order → processing → payment → completed (reserved released) → return → refund → processed return (restocked). It ends by asserting the full movement-type sequence.

The existing `InventoryAdjustmentTest` (original `quantity_change` payload) still passes unchanged.

On the full suite, 83 tests pass and 9 fail. The same 9 failed before this work (75 passed / 9 failed at baseline), and none of them involve inventory code:
- `ProductCrudTest` (3) and `ReturnRequestWorkflowTest::test_variant_stock_…` (1): a partial `PATCH /products/{id}` without `price` writes `NULL` into `products.price`. `ProductsController::normaliseProductData()` overwrites `price` on partial updates.
- `PaymentRefundWorkflowTest` (2): `App\Models\Payment` has no factory.
- `PaymentRefundWorkflowTest` (1): the gateway metadata redaction assertion fails.
- `OrderWorkflowTest::…rejects_inactive…` (1): the validation error key is `items.0.product_id`, but the test expects `items`.
- `CategoryManagementTest` (1): `imagewebp` is missing from the container's GD build.

Other checks:
- `php artisan route:list --path=inventory` shows all 10 inventory routes.
- The list, filter, sort and summary SQL was run against the Docker MySQL 8 database. A reserved-stock check was also run on MySQL inside a rolled-back transaction: on-hand 59, reserved 4, available 55, and `set` below reserved was rejected.
- `vendor/bin/pint` passed on all new files.

## Remaining issues and blockers

1. **Multiple stock locations and stock transfer (Update Stock → "Stock Locations (Optional)", "All Warehouses" filter).** The schema has no warehouses or locations. Supporting them needs new `warehouses` and per-location stock tables, plus a decision on how they relate to the single stock columns that orders deduct. Stock transfer depends on this, so no transfer endpoint was added. This needs a product decision.
2. **Supplier filter on the Inventory list.** There is no supplier entity or product–supplier link. Supplier is recorded as free text on each movement and is searchable in `/inventory/logs`, but products can't be filtered by supplier, and the Update Stock supplier dropdown has no backing list.
3. **Import Stock / Export buttons.** These are not implemented; they need a file format and spec. The frontend can export from the list endpoint for now.
4. **Barcode search.** There is no barcode column; search covers SKU.
5. **`reference_type` is free text** (for example `purchase_order`), because the design only shows "Purchase Order". It can be constrained to an enum once the dropdown options are final.
6. **Inventory Reports trend chart** (stock and value over time). This needs historical snapshots and is outside this API. The current stock figures, value and status distribution are available from `/inventory` and `/inventory/summary`.
7. **Frontend not updated.** `sofiacart-frontend` still shows only the log list at `sales/inventory`. The new endpoints are backward compatible, so nothing breaks.
8. **The pre-existing product partial-update bug** (see Tests above) is outside inventory scope and was not changed.
