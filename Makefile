.PHONY: up down test test-backend test-frontend lint lint-backend lint-frontend coverage coverage-backend coverage-frontend e2e e2e-up e2e-down build test-backend-isolated lint-backend-isolated coverage-backend-isolated load-test-sqlite

up:
	docker compose -f docker-compose.dev.yml up --build -d

down:
	docker compose -f docker-compose.dev.yml down

# Thin wrappers around the exact commands documented in CLAUDE.md/docs/architecture.md
# — nothing new here, just centralized so they don't have to be remembered/typed in full.
# Backend targets need `make up` first (they exec into the running dev container);
# frontend targets need `cd frontend && npm install` first (they run on the host, not in Docker).

test: test-backend test-frontend

test-backend:
	docker compose -f docker-compose.dev.yml exec backend composer test

test-frontend:
	cd frontend && npm run test

lint: lint-backend lint-frontend

lint-backend:
	docker compose -f docker-compose.dev.yml exec backend composer stan
	docker compose -f docker-compose.dev.yml exec backend composer cs
	docker compose -f docker-compose.dev.yml exec backend composer md

lint-frontend:
	cd frontend && npm run check

coverage: coverage-backend coverage-frontend

coverage-backend:
	docker compose -f docker-compose.dev.yml exec backend composer test-coverage

coverage-frontend:
	cd frontend && npm run test:coverage

# Local-only, not run in CI (see docs/architecture.md's Testing and CI section) — needs
# `cd frontend && npx playwright install chromium` once per machine. Genuinely isolated
# (docker-compose.e2e.yml): own backend container, own SQLite file, own Compose project,
# never touches dev's or the PHPUnit stack's data. Leaves the stack up afterward so
# repeat `cd frontend && npx playwright test` runs during debugging don't need a full
# rebuild each time — `make e2e-down` tears it down when done.
e2e: e2e-up
	cd frontend && npm run test:e2e

e2e-up:
	docker compose -f docker-compose.e2e.yml up --build -d
	docker compose -f docker-compose.e2e.yml exec backend sh -c "rm -f var/e2e.db && rm -rf var/cache/e2e && php bin/console doctrine:migrations:migrate --env=e2e --no-interaction"

e2e-down:
	docker compose -f docker-compose.e2e.yml down

build:
	cd frontend && npm run build

# Genuinely isolated backend test environment (docker-compose.test.yml) — its own
# database (a dedicated named volume, never the dev stack's bind-mounted backend/var/),
# no Mailpit, no dev stack required to be up at all. Slower (builds/starts a fresh
# container per invocation) than the test-backend/etc. targets above, which reuse an
# already-running dev container for fast iteration — reach for these instead when you
# want a clean-room check with nothing shared between test and dev.
test-backend-isolated:
	docker compose -f docker-compose.test.yml run --rm backend composer test

lint-backend-isolated:
	docker compose -f docker-compose.test.yml run --rm backend composer stan
	docker compose -f docker-compose.test.yml run --rm backend composer cs
	docker compose -f docker-compose.test.yml run --rm backend composer md

coverage-backend-isolated:
	docker compose -f docker-compose.test.yml run --rm backend composer test-coverage

# One-off measurement, not a CI gate (see docs/adr/0003-sqlite-default-database.md) — always
# against a genuinely fresh database in the isolated stack, same wipe-then-migrate steps
# composer test itself uses, so repeated runs never accumulate leftover seeded rows.
load-test-sqlite:
	docker compose -f docker-compose.test.yml run --rm backend sh -c "rm -f var/test.db && rm -rf var/cache/test && php bin/console doctrine:migrations:migrate --env=test --no-interaction && php bin/console app:load-test-sqlite --env=test"
