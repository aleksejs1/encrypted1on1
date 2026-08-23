#!/bin/sh
set -eu

# Rebuilds and redeploys the app in one step — see docs/deployment.md's
# "Redeploying" section for why every step here is required, not optional:
# `--build` alone doesn't replace the running container; even a fresh
# `up -d` isn't enough on its own, because Symfony's compiled DI container
# (var/cache/prod) lives in the persistent `data` volume and survives a
# redeploy untouched — an already-running FrankenPHP worker keeps serving
# the *old* compiled container against the *new* code until that cache is
# cleared and the app restarted. This is exactly the sequence of manual
# steps that has gone wrong before by being run out of order or skipped.
#
# Assumes you've already pulled the code you want to deploy (git pull, or
# whatever your own process is) — this script only builds and redeploys
# whatever's currently checked out. Run from anywhere; it cd's to the repo
# root itself.
#
# Usage: ./docker/prod/deploy.sh
# Env:   ENV_FILE (default .env.prod), PROD_COMPOSE_FILE (default
#        docker-compose.prod.reverse-proxy.yml — set to
#        docker-compose.prod.yml for the direct-facing topology, or
#        docker-compose.cloud.yml + ENV_FILE=.env.cloud for the
#        multi-tenant cloud topology, see docs/deployment.md), MIGRATION_CONFIG
#        (default empty — set to "--configuration=migrations-mysql.php" when
#        PROD_COMPOSE_FILE=docker-compose.cloud.yml; that topology's database is
#        MySQL, not SQLite, and needs the MySQL-specific migration namespace, see
#        docs/deployment.md's "Using MySQL instead of SQLite" section)

ENV_FILE="${ENV_FILE:-.env.prod}"
PROD_COMPOSE_FILE="${PROD_COMPOSE_FILE:-docker-compose.prod.reverse-proxy.yml}"
MIGRATION_CONFIG="${MIGRATION_CONFIG:-}"
COMPOSE="docker compose -f $PROD_COMPOSE_FILE --env-file $ENV_FILE"

cd "$(dirname "$0")/../.."

# Baked into the footer's version display (only shown if SHOW_VERSION=true —
# see docs/deployment.md's "Frontend build-time" section) — computed here so
# operators don't have to set it by hand. Empty if this isn't a git checkout.
export GIT_SHA="$(git rev-parse --short HEAD 2>/dev/null || true)"

echo "==> Building app image ($PROD_COMPOSE_FILE)..."
$COMPOSE build app

echo "==> Recreating the app container with the new image..."
$COMPOSE up -d app

echo "==> Running database migrations..."
$COMPOSE exec -T app php bin/console doctrine:migrations:migrate $MIGRATION_CONFIG --no-interaction

echo "==> Clearing the compiled container cache (see docs/deployment.md's Redeploying section)..."
$COMPOSE exec -T app rm -rf var/cache/prod

echo "==> Restarting so the running worker picks up the freshly-compiled container..."
$COMPOSE restart app

echo "==> Deployed. Verifying health..."
sleep 2
if $COMPOSE exec -T app curl -fsS http://127.0.0.1/health >/dev/null 2>&1; then
    echo "==> Healthy."
else
    echo "==> WARNING: health check did not succeed — check '$COMPOSE logs app'." >&2
    exit 1
fi
