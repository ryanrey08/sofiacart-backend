# SofiaCart Backend

Laravel 13 REST API backend for SofiaCart with Sanctum authentication, merchant onboarding, catalog, sales, finance, inventory, reports, and a Docker-based development environment.

## Requirements

- PHP 8.3+
- Composer 2+
- SQLite for tests, or MySQL 8 for local development
- Docker + Docker Compose (optional, recommended)

## Local setup

```bash
cp .env.example .env
composer install --prefer-source --no-cache
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve --host=0.0.0.0 --port=8000
```

API base URL: `http://localhost:8000`

Run tests:

```bash
php artisan test
```

## Docker setup

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
```

Services:

- API / Nginx: `http://localhost:8000`
- phpMyAdmin: `http://localhost:8080`
- MySQL: `localhost:3306`
- Redis: `localhost:6379`

## Makefile shortcuts

```bash
make up
make down
make migrate
make seed
make artisan cmd="route:list"
make composer cmd="test"
make shell
```

## Environment defaults

`.env.example` is Docker-friendly by default:

- `DB_HOST=db`
- `DB_DATABASE=sofiacart`
- `DB_USERNAME=sofiacart`
- `DB_PASSWORD=secret`
- `REDIS_HOST=redis`

## Super Admin provisioning

`php artisan migrate --seed` now seeds merchant demo data plus the RBAC roles and permissions, but it does **not** create a permanent admin password.

Create the initial Super Admin with:

```bash
php artisan admin:provision-super-admin admin@example.com "SofiaCart Super Admin"
```

The command hides the one-time password setup token by default. Use `--show-token` only in a secure terminal when you need to capture it manually. Complete setup through `POST /api/admin/auth/reset-password`. Passing `--force` is required when promoting an existing account or provisioning another Super Admin.

## API overview

All `/api/v1/*` routes and `/api/auth/logout`, `/api/auth/me` use `auth:sanctum`.
Merchant users are scoped to their own merchant data. Admin users may access all merchant records.

### Auth

- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/auth/me`

### Merchant

- `POST /api/merchant/register`

Multipart registration accepts business details, store profile, owner information, business permit, store logo, optional banner, and government ID.

### Catalog

- `GET|POST /api/v1/categories`
- `GET /api/v1/categories/stats`
- `POST /api/v1/categories/bulk`
- `GET|PUT|PATCH|DELETE /api/v1/categories/{category}`
- `POST /api/v1/categories/{category}/image`
- `PATCH /api/v1/categories/{category}/status`
- `GET|POST /api/v1/products`
- `GET|PUT|PATCH|DELETE /api/v1/products/{product}`

Supported product/category query params include `search`, `per_page`, and entity-specific filters such as `status` and `category_id`.

#### Category management

Categories are scoped to the authenticated merchant's store (admins may pass `merchant_id`). Records outside the store return `404`, and `CategoryPolicy` enforces store ownership.

| Method & path | Description |
| --- | --- |
| `GET /api/v1/categories` | Paginated list with `products_count`, `children_count`, and `parent`. |
| `GET /api/v1/categories/stats` | `{ "data": { "total", "active", "inactive", "total_products" } }` |
| `GET /api/v1/categories/{id}` | Category detail with parent, `image_url`, `products_count`, and timestamps. |
| `POST /api/v1/categories` | Create a category (JSON or `multipart/form-data` with `image`). Returns `201`. |
| `PUT\|PATCH /api/v1/categories/{id}` | Update a category. Send `remove_image=true` to delete the current image. |
| `DELETE /api/v1/categories/{id}` | Delete a category. Its products and subcategories are kept but unassigned. Returns `204`. |
| `POST /api/v1/categories/{id}/image` | Upload or replace the image (`multipart/form-data`, field `image`). |
| `PATCH /api/v1/categories/{id}/status` | Set `is_active`, or toggle it when no body is sent. |
| `POST /api/v1/categories/bulk` | `{ "action": "delete\|activate\|deactivate", "ids": [1, 2] }` returns `{ "data": { "action", "affected", "ids" } }`. All IDs must belong to the store or the request fails with `422`. |

List query params:

- `search`: matches name, slug, or description.
- `status`: `active` or `inactive`.
- `parent_id`: a category ID, or `none` for top-level categories.
- `sort`: `name_asc`, `name_desc`, `products_desc`, `products_asc`, `newest`, or `oldest`. Default: `sort_order` ascending, then name.
- `per_page`: default `10` (UI uses 10/20/50), maximum `100`; `page` selects the page.
- `merchant_id`: admin only, also accepted by `stats`.

Create/update fields:

| Field | Rules |
| --- | --- |
| `name` | Required on create, max 255. |
| `slug` | Lowercase letters, numbers, and hyphens; unique per store. Generated from `name` when omitted (`home-living`, `home-living-2`, ...). |
| `parent_id` | Optional category in the same store; cannot be the category itself or one of its subcategories. |
| `description` | Rich text HTML, max 500 visible characters. Unsafe markup (scripts, event handlers, `javascript:` links) is stripped. |
| `image` | PNG/JPG/WEBP, max 2 MB (recommended 600x400). Stored on the `public` disk; responses include `image_path` and `image_url`. |
| `sort_order` | Integer >= 0; lower values are listed first. |
| `is_active`, `show_in_nav` | Booleans (`true`/`false`/`1`/`0`; multipart strings are accepted). Default `true`. |
| `meta_title` | Optional, max 255 (recommended 50-60 characters). |
| `meta_description` | Optional, max 500 (recommended 150-160 characters). |

PHP does not parse multipart bodies for `PUT`/`PATCH`. To send an image while updating, use `POST /api/v1/categories/{id}/image`, or `POST` with `_method=PUT`.

`php artisan db:seed --class=CategorySeeder` seeds the mockup categories (Electronics, Apparel, Home & Living, and others, with subcategories) for every merchant.

### Sales

- `GET|POST /api/v1/customers`
- `GET /api/v1/customers/summary`
- `GET|PUT|PATCH|DELETE /api/v1/customers/{customer}`
- `GET|POST /api/v1/orders`
- `GET|PUT|PATCH|DELETE /api/v1/orders/{order}`
- `PATCH /api/v1/orders/{order}/status`
- `GET /api/v1/inventory/logs`
- `POST /api/v1/inventory/adjust`

#### Customers

Customers are merchant-scoped contact records, **not** login accounts: there is no customer password, account activation, welcome email, customer group or price list.

| Field | Rules |
| --- | --- |
| `first_name`, `last_name` | Max 100. When sent, `name` is derived as `"first last"`. `first_name` is required unless `name` is sent. |
| `name` | Max 255. Legacy alternative to first/last name; sending only `name` clears `first_name`/`last_name`. |
| `email` | Optional, lowercased, unique per merchant. `phone` optional, max 20. |
| `customer_type` | `regular` (default), `vip`, `wholesale`. |
| `is_active` | Boolean, default `true`. Responses also include `status` (`active` or `inactive`). |
| `birthday` | `YYYY-MM-DD`, before today. `gender`: `male`, `female`, `other`, `prefer_not_to_say`. |
| `tin` | Max 32; letters, numbers, spaces and hyphens. |
| `default_address` | Object with `line1`, `line2`, `city`, `province`, `postal_code`, `country` (`line1` required when any other part is sent; `null` clears it). The formatted address is also stored in the legacy `address` string used for order shipping snapshots. `address` and `default_address` cannot be sent together. |
| `notes` | Max 5000. `tags`: up to 20 unique strings (max 50 each). |

`GET /api/v1/customers` accepts `search` (name, email, phone), `customer_type`, `status` (`active|inactive`), `registered_from`/`registered_to` (`YYYY-MM-DD`, by creation date), `sort` (`newest` default, `oldest`, `name_asc`, `name_desc`, `orders_desc`, `spent_desc`, `last_order_desc`) and `per_page` (max 100). List and detail responses include `orders_count`, `paid_orders_count`, `total_spent` (paid and partially refunded order totals minus processed refunds) and `last_order_at`; the detail and write responses also include the five latest `recent_orders`.

`GET /api/v1/customers/summary` accepts `date_from`/`date_to` (`YYYY-MM-DD`, default: the 30 days ending today) and returns `period`, `total_customers`, `active_customers` and `inactive_customers` (all time), `new_customers` (created in the period), `returning_customers` (ordered in the period and also before it) and `total_orders` (orders of any status placed in the period).

Customers with orders cannot be deleted (`409`); deactivate them instead. Admins must pass `merchant_id` when creating, and customers with orders cannot be moved to another merchant.

Orders support line items, pagination, status filters, payment-status filters, and date-range filters.
Order creation validates active product statuses, validates submitted unit prices against current catalog prices server-side, locks rows in ascending ID order to prevent deadlocks, and prevents overselling within database transactions. Stock is atomically deducted and logged in `inventory_logs`.
Order cancellation and full refund processing restore product stock exactly once using the `inventory_restored` flag to ensure idempotency.
Inventory adjustments are atomic and create an inventory log entry with the resulting stock.

### Finance

- `GET|POST /api/v1/payments`
- `GET|PUT|PATCH|DELETE /api/v1/payments/{payment}`
- `GET|POST /api/v1/transactions`
- `GET|PUT|PATCH|DELETE /api/v1/transactions/{transaction}`
- `GET|POST /api/v1/refunds`
- `GET|PUT|PATCH|DELETE /api/v1/refunds/{refund}`

Supported filters include `search`, `status`, `type`, `gateway`, `order_id`, and `payment_id` where applicable.
Payment and refund mutations automatically synchronize the linked order's `payment_status` (`paid`, `partially_refunded`, `refunded`, `unpaid`).
Refunds transactionally enforce refundable balances against completed payments.
Sensitive payment gateway metadata (tokens, secrets, API keys, passwords, authorization credentials, CVV/card numbers) is automatically sanitized and redacted across all payment, transaction, and refund API responses.

### Commerce workflow and unsupported features

- **Workflow**: `Product -> Category -> Inventory -> Order -> Payment -> Transaction -> Refund` is fully verified, transactional, and merchant-isolated.
- **Product Variants/Options**: Not supported by current database schema; products maintain a single SKU, price, and stock quantity.
- **Category Hierarchy**: Categories support an optional `parent_id` within the same merchant store.
- **Discounts/Shipping breakdown**: Order creation calculates totals based on validated line item quantities and unit prices; arbitrary discount/shipping tables are not in the existing schema.

### Reports

- `GET /api/v1/reports/sales`
- `GET /api/v1/reports/customers`
- `GET /api/v1/reports/products`
- `GET /api/v1/reports/inventory`

Supported report query params include `date_from`, `date_to`, `limit`, and `low_stock_threshold`.

### Admin auth and RBAC

- `POST /api/admin/auth/login`
- `POST /api/admin/auth/forgot-password`
- `POST /api/admin/auth/reset-password`
- `POST /api/admin/auth/logout`
- `POST /api/admin/auth/logout-all`
- `GET /api/admin/auth/me`
- `GET /api/admin/auth/sessions`
- `DELETE /api/admin/auth/sessions/{tokenId}`

Admin access reuses the main `users` table plus Sanctum, but all admin routes also require the `admin` middleware and database-backed permissions. Seeded system roles:

- Super Admin
- Admin
- Merchant Manager
- Order Manager
- Product Manager
- Customer Support

System permissions use the `domain.action` convention, for example `dashboard.view`, `merchants.manage`, `payments.refund`, and `users.assign_super_admin`.

### Admin API inventory

Reused existing controllers behind admin-only routes:

- `GET|POST|PUT|PATCH /api/admin/orders*`
- `GET|POST|PUT|PATCH|DELETE /api/admin/products*`
- `GET|POST|PUT|PATCH /api/admin/payments*`
- `GET|POST|PUT|PATCH /api/admin/refunds*`
- `GET /api/admin/transactions*`
- `GET|POST /api/admin/inventory/*`
- `GET /api/admin/reports/sales|customers|products|inventory`

New admin management endpoints:

- `GET /api/admin/dashboard`
- `GET /api/admin/merchants`
- `GET /api/admin/merchants/{merchant}`
- `PATCH /api/admin/merchants/{merchant}/status`
- `GET /api/admin/merchants/{merchant}/onboarding-history`
- `GET /api/admin/merchants/{merchant}/billing`
- `GET /api/admin/customers`
- `GET /api/admin/customers/{customer}`
- `GET /api/admin/customers/{customer}/orders`
- `GET /api/admin/reports/platform`
- `GET /api/admin/reports/export`
- `GET|PUT /api/admin/settings`
- `GET|POST /api/admin/users`
- `GET|PUT|PATCH|DELETE /api/admin/users/{user}`
- `GET /api/admin/users/{user}/activity`
- `GET|POST /api/admin/roles`
- `GET|PUT|PATCH|DELETE /api/admin/roles/{role}`
- `GET|POST /api/admin/permissions`
- `GET|PUT|PATCH|DELETE /api/admin/permissions/{permission}`
- `GET /api/admin/logs`
- `GET /api/admin/logs/{log}`

### Phase 3 implementation notes

Completed:

- Isolated admin auth endpoints with rate limiting, token expiry, logout, current-admin, forgot/reset password, and audit logging.
- Admin session revocation endpoints for all sessions and individual issued tokens.
- Database-backed RBAC tables, seeded system roles/permissions, admin settings storage, and append-only admin audit logs.
- Merchant approval/status management, dashboard metrics, admin user/role/permission/settings/log APIs, and admin-only wrappers for core commerce controllers.
- Last-active-Super-Admin protection, direct-permission/role assignment guards, and one-time Super Admin provisioning command.

Changed existing behavior:

- Refund creation/update now enforce refundable-balance checks and synchronize full refunds to payment/order payment status.
- Order status updates now enforce explicit transition rules.
- No default admin password is seeded anymore.

Still dependent on external integrations:

- Payment reconciliation and outbound password reset delivery depend on the deployment mailer/payment gateway configuration.
- Private document delivery currently exposes stored file paths only; serving signed private downloads should be handled at the storage edge if required.

## Validation and file uploads

- Merchant registration validates unique email, store slug, and TIN.
- Phone numbers use a Philippine mobile format rule.
- Store slugs use lowercase letters, numbers, and hyphens.
- Permit and government ID uploads accept PDF/JPG/PNG up to 5 MB.
- Store logos accept JPG/PNG up to 2 MB.
- Optional store banners accept JPG/PNG up to 5 MB.
- Product image uploads accept JPG/PNG up to 5 MB per image.
- Category image uploads accept PNG/JPG/WEBP up to 2 MB.

# Orders and returns (merchant back office)

Authenticated merchant accounts use `/api/v1/orders` (create, list/filter, detail, notes and status) and `/api/v1/return-requests` (list, create, detail, review). Admins can use these routes under the existing admin account scope; merchant requests cannot choose another merchant's records. There is **no authenticated customer identity** linked to `Customer`, so these are **not customer-facing endpoints**. Do not expose them to a storefront until customer authentication and ownership checks exist.

Order creation snapshots the customer's address and server-calculated item prices, subtotal, zero discount/shipping and total. Product variants use variant price and stock; ordinary products use product price and stock. Variant updates preserve IDs for unchanged SKUs; ordered variants and products cannot be removed. No discount or shipping rate engine is configured, so those amounts are zero rather than accepting client-provided amounts. Existing historical orders retain `null` for newly added snapshots. Orders can progress pending → processing → completed (paid only), or pending/processing → cancelled (unpaid only). Item/customer changes, direct payment-status writes and deletion are unavailable to avoid corrupting stock and financial history. Existing custom line items remain supported with explicitly supplied price and no inventory tracking.

`POST /api/v1/return-requests` accepts `order_id`, `customer_id`, required `reason` and `notes`, `items: [{order_item_id, quantity}]`, and up to five optional JPG/PNG/WebP evidence files (5 MB each). The supplied customer must belong to the order; only completed, paid orders within `RETURN_WINDOW_DAYS` (default 30, measured from `ordered_at`) qualify. Quantities already held by pending, approved or processed requests count against returnable inventory. `PATCH /api/v1/return-requests/{returnRequest}` accepts `status: approved|rejected|processed`; processing a positive-value return requires a unique `refund_id` referencing an **already processed**, matching-amount refund of the same order and payment; zero-value items need no refund. Refunds are existing financial records, **not** return requests; this API neither charges nor sends a gateway refund, nor issues store credit. Operators must verify external settlement before recording a refund as processed using the existing refund API. Processing restocks only returned quantities once. A refund by itself does not establish physical receipt and never restocks. Processed refunds/payments tied to returns cannot be edited or deleted. Evidence remains on private local storage; authenticated scoped users can download it using the `evidence[].url` returned by the return API.

Additive migrations: `2026_10_01_020000_create_return_requests_table.php` and `2026_10_01_020100_add_order_snapshots.php`. Relevant changes: order requests/controller/resources/models, inventory and payment/refund controllers, return controller/models/resource, routes, returns config and feature tests. Run `php artisan test --filter=OrderWorkflowTest`, `php artisan test --filter=ReturnRequestWorkflowTest`, `php artisan test --filter=PaymentRefundWorkflowTest`, and `php artisan route:list --path=return-requests` once dependencies are available. Never run destructive migration commands on production data.
