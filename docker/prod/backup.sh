#!/bin/sh
set -eu

# Run from the repo root (where docker-compose.prod.yml lives), typically via
# cron on the host — see README.md's "Backups" section. Uses sqlite3's online
# .backup command, not a raw file copy, so a concurrent write from the
# running app can never produce a corrupt snapshot.
#
# Every docker compose invocation against docker-compose.prod.yml needs its
# required env vars resolved (APP_SECRET etc.) even for commands like `exec`
# that don't start anything new — cron's environment is minimal/empty, so
# this can't rely on variables already being exported the way an interactive
# shell might have them.
#
# Usage: ./docker/prod/backup.sh
# Env:   ENV_FILE (default .env.prod), BACKUP_DIR (default ./backups), RETENTION_DAYS (default 14),
#        PROD_COMPOSE_FILE (default docker-compose.prod.yml — set to
#        docker-compose.prod.reverse-proxy.yml if that's what you deployed with)

ENV_FILE="${ENV_FILE:-.env.prod}"
PROD_COMPOSE_FILE="${PROD_COMPOSE_FILE:-docker-compose.prod.yml}"
COMPOSE="docker compose -f $PROD_COMPOSE_FILE --env-file $ENV_FILE"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
TMP_PATH="/tmp/backup-$TIMESTAMP.db"

mkdir -p "$BACKUP_DIR"

$COMPOSE exec -T app sqlite3 var/data.db ".backup '$TMP_PATH'"
$COMPOSE cp "app:$TMP_PATH" "$BACKUP_DIR/data-$TIMESTAMP.db"
$COMPOSE exec -T app rm "$TMP_PATH"

# Prune old backups. This only manages the local $BACKUP_DIR — getting that
# directory itself to offsite/cloud storage is the operator's own concern,
# deliberately not this script's.
find "$BACKUP_DIR" -name 'data-*.db' -mtime "+$RETENTION_DAYS" -delete

echo "Backup written to $BACKUP_DIR/data-$TIMESTAMP.db"
