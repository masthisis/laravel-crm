COMPOSE        := docker compose -f docker-compose.yml
COMPOSE_STG    := docker compose -f docker-compose.staging.yml
COMPOSE_PROD   := docker compose -f docker-compose.production.yml

.PHONY: help up down restart logs ps shell tinker key migrate fresh seed seed-demo \
        test lint analyse cache-clear staging-up staging-down staging-migrate \
        prod-up prod-down prod-migrate

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

up: ## Build and start the local stack
	$(COMPOSE) up -d --build

down: ## Stop the local stack
	$(COMPOSE) down

restart: ## Restart the local stack
	$(COMPOSE) restart

logs: ## Tail logs
	$(COMPOSE) logs -f --tail=100

ps: ## Show container status
	$(COMPOSE) ps

shell: ## Open a shell in the app container
	$(COMPOSE) exec app sh

tinker: ## Open Laravel tinker
	$(COMPOSE) exec app php artisan tinker

key: ## Generate the application key
	$(COMPOSE) exec app php artisan key:generate

migrate: ## Run migrations
	$(COMPOSE) exec app php artisan migrate --force

fresh: ## Recreate the database (drop + migrate + seed)
	$(COMPOSE) exec app php artisan migrate:fresh --seed --force

seed: ## Seed base data (roles, sources, rules, admin user)
	$(COMPOSE) exec app php artisan db:seed --force

seed-demo: ## Seed demo data (leads, customers, orders)
	$(COMPOSE) exec -e SEED_DEMO_DATA=true app php artisan db:seed --class=DemoDataSeeder --force

test: ## Run the test suite
	$(COMPOSE) exec app php artisan test

lint: ## Run Pint (code style fixer)
	$(COMPOSE) exec app vendor/bin/pint

analyse: ## Run PHPStan
	$(COMPOSE) exec app vendor/bin/phpstan analyse

cache-clear: ## Clear all application caches
	$(COMPOSE) exec app php artisan optimize:clear

staging-up: ## Deploy the staging stack (pull latest image)
	$(COMPOSE_STG) pull app worker scheduler
	$(COMPOSE_STG) build nginx
	$(COMPOSE_STG) up -d

staging-down: ## Stop staging
	$(COMPOSE_STG) down

staging-migrate: ## Run staging migrations
	$(COMPOSE_STG) exec app php artisan migrate --force

prod-up: ## Deploy the production stack (pull latest image)
	$(COMPOSE_PROD) pull app worker scheduler
	$(COMPOSE_PROD) build nginx
	$(COMPOSE_PROD) up -d

prod-down: ## Stop production
	$(COMPOSE_PROD) down

prod-migrate: ## Run production migrations
	$(COMPOSE_PROD) exec app php artisan migrate --force
