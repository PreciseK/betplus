.PHONY: install lint test build gates \
	install-platform install-blackred install-ussd install-heritage install-web \
	lint-platform lint-blackred lint-ussd lint-heritage lint-web \
	test-platform test-blackred test-ussd test-heritage test-web \
	build-platform build-heritage build-web \
	gate-randomness gate-purity gate-money gate-ussd gate-schema

# Override on the command line if these tools aren't on PATH, e.g.:
#   make install COMPOSER="php C:/php/composer.phar"
COMPOSER ?= composer
PNPM     ?= pnpm
UV       ?= uv

## install — every workspace's dependencies
install: install-platform install-blackred install-ussd install-heritage install-web

install-platform:
	cd apps/platform && $(COMPOSER) install

install-blackred:
	cd apps/engine-blackred && $(COMPOSER) install

install-ussd:
	cd apps/ussd && $(COMPOSER) install

install-heritage:
	cd apps/engine-heritage && $(UV) sync

install-web:
	$(PNPM) install

## lint — every workspace's static checks
lint: lint-platform lint-blackred lint-ussd lint-heritage lint-web

lint-platform:
	cd apps/platform && $(COMPOSER) exec pint -- --test

lint-blackred:
	cd apps/engine-blackred && $(COMPOSER) stan

lint-ussd:
	cd apps/ussd && $(COMPOSER) stan

lint-heritage:
	cd apps/engine-heritage && $(UV) run ruff check src tests

lint-web:
	$(PNPM) --dir apps/web run lint

## test — every workspace's test suite
test: test-platform test-blackred test-ussd test-heritage test-web

test-platform:
	cd apps/platform && php artisan test

test-blackred:
	cd apps/engine-blackred && $(COMPOSER) test

test-ussd:
	cd apps/ussd && $(COMPOSER) test

test-heritage:
	cd apps/engine-heritage && $(UV) run pytest

test-web:
	$(PNPM) --dir apps/web exec tsc --noEmit

## build — every workspace's production artefact
build: build-platform build-heritage build-web

build-platform:
	cd apps/platform && $(COMPOSER) install --no-dev --optimize-autoloader

build-heritage:
	cd apps/engine-heritage && $(UV) sync --no-dev

build-web:
	$(PNPM) --dir apps/web run build

## gates — project-specific rules no linter enforces on its own (Story 1.5)
gates: gate-randomness gate-purity gate-money gate-ussd gate-schema

gate-randomness:
	php tools/gates/prohibited-randomness.php

gate-purity:
	php tools/gates/engine-purity.php

gate-money:
	php tools/gates/money-naming.php

gate-ussd:
	php tools/gates/ussd-screen-length.php

# Compares the live schema against a fresh migrate. Passes with a note if no DB_NAME is
# configured — that is expected until Story 1.4 provisions staging.
gate-schema:
	php tools/gates/schema-drift.php
