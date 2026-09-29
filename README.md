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

## Seeded accounts

After `php artisan migrate --seed`:

- Admin: `admin@sofiacart.test`
- Password: `password`

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

## Validation and file uploads

- Merchant registration validates unique email, store slug, and TIN.
- Phone numbers use a Philippine mobile format rule.
- Store slugs use lowercase letters, numbers, and hyphens.
- Permit and government ID uploads accept PDF/JPG/PNG up to 5 MB.
- Store logos accept JPG/PNG up to 2 MB.
- Optional store banners accept JPG/PNG up to 5 MB.
- Product image uploads accept JPG/PNG up to 5 MB per image.
