up:
	docker compose up -d --build

down:
	docker compose down

migrate:
	docker compose exec app php artisan migrate

seed:
	docker compose exec app php artisan db:seed

artisan:
	docker compose exec app php artisan $(cmd)

composer:
	docker compose exec app composer $(cmd)

shell:
	docker compose exec app sh
