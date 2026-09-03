MAKEFILE_DIR := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
DC ?= docker compose -f $(MAKEFILE_DIR)docker-compose.yml

.PHONY: up down restart build rebuild sh root logs ps artisan composer test migrate fresh queue redis mysql

up: ## Start the whole stack
	$(DC) up -d

down: ## Stop the stack (add v=1 to also drop the volumes)
	$(DC) down $(if $(v),-v,)

restart:
	$(DC) restart

build:
	$(DC) build

rebuild:
	$(DC) build --no-cache

sh: ## Shell inside the app container as www-data
	$(DC) exec -u www-data app bash

root: ## Shell inside the app container as root
	$(DC) exec app bash

logs:
	$(DC) logs -f --tail=100 $(s)

ps:
	$(DC) ps

artisan: ## make artisan c="migrate --seed"
	$(DC) exec -u www-data app php artisan $(c)

composer: ## make composer c="require vendor/pkg"
	$(DC) exec -u www-data app composer $(c)

test:
	$(DC) exec -u www-data app php artisan test

migrate:
	$(DC) exec -u www-data app php artisan migrate

fresh:
	$(DC) exec -u www-data app php artisan migrate:fresh --seed

queue: ## Restart the queue worker (pick up new code)
	$(DC) restart queue

redis:
	$(DC) exec redis redis-cli

mysql:
	$(DC) exec mysql sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"'
