.PHONY: up down test test-backend test-frontend lint lint-backend lint-frontend coverage coverage-backend coverage-frontend e2e build

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

lint-frontend:
	cd frontend && npm run check

coverage: coverage-backend coverage-frontend

coverage-backend:
	docker compose -f docker-compose.dev.yml exec backend composer test-coverage

coverage-frontend:
	cd frontend && npm run test:coverage

# Local-only, not run in CI (see docs/architecture.md's Testing and CI section) — needs
# the dev stack up (`make up`) and, once per machine, `cd frontend && npx playwright install chromium`.
e2e:
	cd frontend && npm run test:e2e

build:
	cd frontend && npm run build
