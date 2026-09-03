#!/bin/sh
set -eu

# Exercises docker/prod/backup.sh and docker/prod/restore.sh for real, against a real
# throwaway prod image and real seeded data — not just reviewing them by eye. Builds
# docker-compose.prod.reverse-proxy.yml (not docker-compose.prod.yml: auto_https is off
# in that mode, so Caddy never attempts a real Let's Encrypt certificate — the direct-
# facing file would hang/fail here with no real public domain pointing at the runner),
# seeds real demo data (app:reset-demo-data — real accounts, real anketa history, the
# same command docs/deployment.md's demo-mode cron job runs), takes a backup, destroys
# the live database, restores it, and confirms the restored data is byte-identical to
# a fresh backup of it — not just "restore.sh exited 0".
#
# Run from the repo root: ./scripts/test-backup-restore.sh
# Needs nothing pre-existing — builds its own throwaway stack under its own Compose
# project name and tears it down (including its volumes) on exit, success or failure.

WORKDIR=$(mktemp -d)
ENV_FILE="$WORKDIR/.env.prod"
BACKUP_DIR="$WORKDIR/backups"
PROD_COMPOSE_FILE="docker-compose.prod.reverse-proxy.yml"
export PROD_COMPOSE_FILE
# Exported (not passed via `docker compose -p`) so backup.sh/restore.sh — which construct
# their own `docker compose -f ... --env-file ...` invocations with no -p of their own —
# target this same throwaway project instead of Compose's directory-derived default,
# without needing to modify either script to accept a project name.
export COMPOSE_PROJECT_NAME="e1o1-backup-restore-test"
COMPOSE="docker compose -f $PROD_COMPOSE_FILE --env-file $ENV_FILE"

cleanup() {
    $COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$WORKDIR"
}
trap cleanup EXIT

# `docker compose start app` (both directly below and inside restore.sh, which this
# script doesn't own/modify) returns as soon as the container process launches, not once
# FrankenPHP/Caddy is actually accepting connections (the image's own HEALTHCHECK has a
# --start-period=10s for exactly this reason — docker/prod/deploy.sh's own restart step
# guards the identical race with a plain `sleep 2`). A bare curl right after `start`
# would then be a real, if intermittent, false failure on a slower runner. Polls instead
# of assuming a fixed delay is enough, and works the same regardless of which of the two
# call sites (this script's own restart, or restore.sh's internal one) just happened.
wait_for_health() {
    i=0
    while [ "$i" -lt 30 ]; do
        if $COMPOSE exec -T app curl -fsS http://127.0.0.1/health >/dev/null 2>&1; then
            return 0
        fi
        i=$((i + 1))
        sleep 1
    done
    echo "FAIL: app did not become healthy within 30s" >&2
    exit 1
}

cat >"$ENV_FILE" <<EOF
APP_SECRET=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
SERVER_NAME=localhost
FRONTEND_URL=http://localhost
MAILER_DSN=smtp://localhost:1025
MAILER_FROM=test@localhost
APP_INTERNAL_BIND=127.0.0.1:18099
EOF

echo "==> Building and starting the app"
$COMPOSE up -d --build --wait

echo "==> Running migrations"
$COMPOSE exec -T app php bin/console doctrine:migrations:migrate --no-interaction

echo "==> Seeding real demo data"
$COMPOSE exec -T app php bin/console app:reset-demo-data

# Not `... | grep ... | tail -1` piped directly into the assignment: plain `sh`/dash has
# no `pipefail`, so a failing `dbal:run-sql` (SQLite busy, a console error) would be
# masked by `tail`'s own always-zero exit status, silently turning $SEEDED_COUNT/
# $RESTORED_COUNT into an empty string instead of aborting under `set -e`. Capturing the
# console command's own output as a standalone command substitution keeps it subject to
# `set -e` on its own, and the explicit numeric check below catches anything else that
# would make the count unparseable.
anketa_count() {
    output=$($COMPOSE exec -T app php bin/console dbal:run-sql "SELECT COUNT(*) AS c FROM anketas")
    count=$(printf '%s' "$output" | grep -oE '[0-9]+' | tail -1)
    case "$count" in
        '' | *[!0-9]*)
            echo "FAIL: could not parse an anketa count from dbal:run-sql output:" >&2
            echo "$output" >&2
            exit 1
            ;;
    esac
    echo "$count"
}

SEEDED_COUNT=$(anketa_count)
if [ "$SEEDED_COUNT" -eq 0 ]; then
    echo "FAIL: app:reset-demo-data seeded zero anketas — nothing real to back up" >&2
    exit 1
fi
echo "    seeded $SEEDED_COUNT anketas"

echo "==> Taking a backup"
BACKUP_OUTPUT=$(ENV_FILE="$ENV_FILE" BACKUP_DIR="$BACKUP_DIR" sh ./docker/prod/backup.sh)
echo "$BACKUP_OUTPUT"
BACKUP_FILE=$(echo "$BACKUP_OUTPUT" | sed -n 's/^Backup written to //p')
if [ ! -f "$BACKUP_FILE" ]; then
    echo "FAIL: backup.sh did not produce $BACKUP_FILE" >&2
    exit 1
fi
BACKUP_HASH=$(sha256sum "$BACKUP_FILE" | cut -d' ' -f1)

echo "==> Destroying the live database (simulating real data loss)"
$COMPOSE stop app
EMPTY_DB="$WORKDIR/empty.db"
: >"$EMPTY_DB"
$COMPOSE cp "$EMPTY_DB" app:/app/var/data.db
$COMPOSE start app
wait_for_health

echo "==> Restoring the backup"
ENV_FILE="$ENV_FILE" sh ./docker/prod/restore.sh "$BACKUP_FILE"
wait_for_health

RESTORED_COUNT=$(anketa_count)
if [ "$RESTORED_COUNT" != "$SEEDED_COUNT" ]; then
    echo "FAIL: restored anketa count ($RESTORED_COUNT) != seeded count ($SEEDED_COUNT)" >&2
    exit 1
fi

echo "==> Taking a fresh backup of the restored database"
POST_RESTORE_OUTPUT=$(ENV_FILE="$ENV_FILE" BACKUP_DIR="$BACKUP_DIR" sh ./docker/prod/backup.sh)
echo "$POST_RESTORE_OUTPUT"
POST_RESTORE_FILE=$(echo "$POST_RESTORE_OUTPUT" | sed -n 's/^Backup written to //p')
POST_RESTORE_HASH=$(sha256sum "$POST_RESTORE_FILE" | cut -d' ' -f1)

if [ "$BACKUP_HASH" != "$POST_RESTORE_HASH" ]; then
    echo "FAIL: restored database is not byte-identical to the original backup" >&2
    echo "  original:  $BACKUP_HASH ($BACKUP_FILE)" >&2
    echo "  restored:  $POST_RESTORE_HASH ($POST_RESTORE_FILE)" >&2
    exit 1
fi

echo "PASS: backup -> destroy -> restore round-trip produced byte-identical data ($SEEDED_COUNT anketas, sha256 $BACKUP_HASH)"
