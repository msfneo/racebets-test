.DEFAULT_GOAL := help
COMPOSE := docker compose
PHP     := $(COMPOSE) exec -T app php

.PHONY: help up down restart build logs shell mysql migrate fresh seed test test-unit test-integration test-concurrency smoke

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

up: ## Build if needed, start the stack and apply migrations
	$(COMPOSE) up -d --build
	@echo "API ready on http://localhost:$${APP_PORT:-8080}"

down: ## Stop the stack (keeps the database volume)
	$(COMPOSE) down

restart: ## Restart the application container
	$(COMPOSE) restart app web

build: ## Rebuild the application image
	$(COMPOSE) build

logs: ## Follow the application logs
	$(COMPOSE) logs -f app web

shell: ## Open a shell in the application container
	$(COMPOSE) exec app bash

mysql: ## Open a MySQL prompt on the development schema
	$(COMPOSE) exec db mysql -uwallet -psecret wallet

migrate: ## Apply pending migrations
	$(PHP) bin/console migrate

fresh: ## Drop every table and re-apply all migrations
	$(PHP) bin/console migrate:fresh

seed: ## Insert demo customers and transactions
	$(PHP) bin/console seed

test: ## Run the whole test suite
	$(PHP) vendor/bin/phpunit

test-unit: ## Run the unit tests only
	$(PHP) vendor/bin/phpunit --testsuite unit

test-integration: ## Run the integration tests only
	$(PHP) vendor/bin/phpunit --testsuite integration

test-concurrency: ## Run the parallel-request tests only
	$(PHP) vendor/bin/phpunit --group concurrency

smoke: ## Exercise the API end to end with curl
	./bin/smoke-test.sh
