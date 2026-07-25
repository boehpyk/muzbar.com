.DEFAULT_GOAL := help

DC      := docker compose
DC_DEV  := docker compose -f docker-compose.yml -f docker-compose.dev.yml
EXEC    := $(DC_DEV) exec -T app
DUMP_DIR := backups

#-----------------------------------------------------------
# Stack
#-----------------------------------------------------------
up.dev: ## Start the full stack (dev: Xdebug, live mount, Mailpit, 127.0.0.1 ports)
	$(DC_DEV) up -d --build

down.dev: ## Stop the dev stack
	$(DC_DEV) down

up.prod: ## Start in production mode (no override file)
	$(DC) up -d --build

down.prod: ## Stop the prod stack
	$(DC) down

logs: ## Tail logs (usage: make logs [c=app])
	$(DC_DEV) logs --tail=200 -f $(c)

bash: ## Shell into the app container
	$(DC_DEV) exec app bash

#-----------------------------------------------------------
# Application (Symfony / Doctrine)
#-----------------------------------------------------------
console: ## Run a Symfony console command (usage: make console cmd="about")
	$(EXEC) php bin/console $(cmd)

migrate: ## Run Doctrine migrations
	$(EXEC) php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

migration.make: ## Generate a migration from mapping changes
	$(EXEC) php bin/console make:migration

composer: ## Run composer (usage: make composer cmd="require foo/bar")
	$(EXEC) composer $(cmd)

db.dump: ## Dump the database (custom format) to backups/
	@mkdir -p $(DUMP_DIR)
	@out="$(if $(file),$(file),$(DUMP_DIR)/muzbar_$$(date +%Y%m%d_%H%M%S).dump)"; \
	echo "Dumping to $$out ..."; \
	if $(DC) exec -T postgres sh -c 'pg_dump -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -Fc --no-owner --no-privileges' > "$$out.part"; then \
		mv "$$out.part" "$$out"; echo "Done: $$out"; \
	else rm -f "$$out.part"; echo "pg_dump failed." >&2; exit 1; fi

#-----------------------------------------------------------
# Quality gates
#-----------------------------------------------------------
cs: ## Fix code style (php-cs-fixer)
	$(EXEC) vendor/bin/php-cs-fixer fix

cs.check: ## Check code style without changing files
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

stan: ## Static analysis (PHPStan max) — warms the container first for Symfony inference
	$(EXEC) php bin/console cache:warmup --quiet
	$(EXEC) vendor/bin/phpstan analyse --no-progress

deptrac: ## Enforce hexagonal layer boundaries
	$(EXEC) vendor/bin/deptrac analyse --no-progress

test.db: ## Create + migrate the dedicated test database (muzbar_test)
	$(DC_DEV) exec -T -e APP_ENV=test app php bin/console doctrine:database:create --if-not-exists --no-interaction
	$(DC_DEV) exec -T -e APP_ENV=test app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

test: test.db ## Run the test suite (opts: filter=, file=) — provisions muzbar_test first
	$(DC_DEV) exec -T -e APP_ENV=test app php vendor/bin/phpunit $(if $(filter),--filter=$(filter)) $(file)

check: cs.check stan deptrac test ## Run all quality gates (the Definition-of-Done chain)

#-----------------------------------------------------------
# Git hooks
#-----------------------------------------------------------
hooks.install: ## Point git at the tracked hooks in scripts/git-hooks/
	git config core.hooksPath scripts/git-hooks
	chmod +x scripts/git-hooks/*
	@echo "core.hooksPath -> $$(git config core.hooksPath)"

#-----------------------------------------------------------
# Help
#-----------------------------------------------------------
help: ## Show this help
	@grep -E '^[a-zA-Z_.-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.PHONY: up.dev down.dev up.prod down.prod logs bash console migrate migration.make \
        composer db.dump cs cs.check stan deptrac test.db test check hooks.install help
