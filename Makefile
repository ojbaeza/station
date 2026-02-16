# Station - Development Makefile
# Usage: make <target>

.PHONY: help install hooks test test-unit test-feature test-integration test-coverage \
        analyse cs-check cs-fix quality shell up down restart logs \
        build clean fresh migrate

# Default target
help:
	@echo "Station Development Commands"
	@echo ""
	@echo "Setup:"
	@echo "  make install      Install all dependencies (PHP + Node)"
	@echo "  make hooks        Install Git hooks (pre-commit, pre-push)"
	@echo "  make build        Build frontend assets"
	@echo "  make fresh        Clean install (remove vendors, reinstall)"
	@echo ""
	@echo "Docker:"
	@echo "  make up           Start all Docker services"
	@echo "  make up-debug     Start with debug tools (Kafka UI, Beanstalkd Console)"
	@echo "  make down         Stop all Docker services"
	@echo "  make restart      Restart all Docker services"
	@echo "  make logs         Show Docker logs (follow mode)"
	@echo "  make shell        Open shell in PHP container"
	@echo ""
	@echo "Testing:"
	@echo "  make test         Run all tests"
	@echo "  make test-unit    Run unit tests only"
	@echo "  make test-feature Run feature tests only"
	@echo "  make test-integration Run integration tests only"
	@echo "  make test-coverage Run tests with coverage report"
	@echo "  make test-filter F=<name> Run specific test by name"
	@echo ""
	@echo "Code Quality:"
	@echo "  make analyse      Run PHPStan static analysis"
	@echo "  make cs-check     Check code style (dry-run)"
	@echo "  make cs-fix       Fix code style issues"
	@echo "  make quality      Run all quality checks (analyse + cs-check + test)"
	@echo ""
	@echo "Database:"
	@echo "  make migrate      Run database migrations"
	@echo ""
	@echo "Utilities:"
	@echo "  make clean        Remove generated files and caches"

# ===========================================
# Setup
# ===========================================

install:
	composer install
	npm install

hooks:
	./scripts/install-hooks.sh

build:
	npm run build

fresh: clean
	rm -rf vendor node_modules
	composer install
	npm install
	npm run build

# ===========================================
# Docker
# ===========================================

up:
	docker compose up -d

up-debug:
	docker compose --profile debug up -d

down:
	docker compose down

restart: down up

logs:
	docker compose logs -f

shell:
	docker exec -it station_php sh

# ===========================================
# Testing
# ===========================================

test:
	docker exec station_php bash -c "XDEBUG_MODE=off php vendor/bin/phpunit"

test-unit:
	docker exec station_php bash -c "XDEBUG_MODE=off php vendor/bin/phpunit --testsuite=Unit"

test-feature:
	docker exec station_php bash -c "XDEBUG_MODE=off php vendor/bin/phpunit --testsuite=Feature"

test-integration:
	docker exec station_php bash -c "XDEBUG_MODE=off php vendor/bin/phpunit --testsuite=Integration"

test-coverage:
	docker exec station_php bash -c "XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-html coverage"

test-filter:
	docker exec station_php bash -c "XDEBUG_MODE=off php vendor/bin/phpunit --filter $(F)"

# ===========================================
# Code Quality
# ===========================================

analyse:
	docker exec station_php bash -c "./vendor/bin/phpstan analyse"

cs-check:
	docker exec station_php bash -c "./vendor/bin/php-cs-fixer fix --dry-run --diff"

cs-fix:
	docker exec station_php bash -c "./vendor/bin/php-cs-fixer fix"

quality: analyse cs-check test

# ===========================================
# Database
# ===========================================

migrate:
	docker exec station_php bash -c "php artisan migrate"

# ===========================================
# Utilities
# ===========================================

clean:
	rm -rf coverage
	rm -rf .phpunit.cache
	rm -rf .php-cs-fixer.cache
	docker exec station_php bash -c "php artisan cache:clear" 2>/dev/null || true
	docker exec station_php bash -c "php artisan config:clear" 2>/dev/null || true
