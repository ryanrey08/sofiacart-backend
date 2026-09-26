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

The command prints a one-time password setup token. Complete setup through `POST /api/admin/auth/reset-password`.

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
- `GET|PUT|PATCH|DELETE /api/v1/categories/{category}`
- `GET|POST /api/v1/products`
- `GET|PUT|PATCH|DELETE /api/v1/products/{product}`

Supported product/category query params include `search`, `per_page`, and entity-specific filters such as `status` and `category_id`.

### Sales

- `GET|POST /api/v1/customers`
- `GET|PUT|PATCH|DELETE /api/v1/customers/{customer}`
- `GET|POST /api/v1/orders`
- `GET|PUT|PATCH|DELETE /api/v1/orders/{order}`
- `PATCH /api/v1/orders/{order}/status`
- `GET /api/v1/inventory/logs`
- `POST /api/v1/inventory/adjust`

Orders support line items, pagination, status filters, payment-status filters, and date-range filters.
Inventory adjustments are atomic and create an inventory log entry with the resulting stock.

### Finance

- `GET|POST /api/v1/payments`
- `GET|PUT|PATCH|DELETE /api/v1/payments/{payment}`
- `GET|POST /api/v1/transactions`
- `GET|PUT|PATCH|DELETE /api/v1/transactions/{transaction}`
- `GET|POST /api/v1/refunds`
- `GET|PUT|PATCH|DELETE /api/v1/refunds/{refund}`

Supported filters include `search`, `status`, `type`, `gateway`, `order_id`, and `payment_id` where applicable.

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
- `GET /api/admin/auth/me`

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
