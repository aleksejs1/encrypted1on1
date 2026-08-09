#!/bin/sh
set -eu

# Restores a backup created by backup.sh. Stops the app first — writes
# during the restore would otherwise be lost or leave the DB inconsistent —
# then swaps in the backup file and restarts. Run from the repo root.
#
# Usage: ./docker/prod/restore.sh path/to/backup.db
# Env:   ENV_FILE (default .env.prod)

BACKUP_FILE="${1:?usage: restore.sh path/to/backup.db}"
ENV_FILE="${ENV_FILE:-.env.prod}"
COMPOSE="docker compose -f docker-compose.prod.yml --env-file $ENV_FILE"

if [ ! -f "$BACKUP_FILE" ]; then
    echo "No such file: $BACKUP_FILE" >&2
    exit 1
fi

$COMPOSE stop app
$COMPOSE cp "$BACKUP_FILE" app:/app/var/data.db
$COMPOSE start app

echo "Restored $BACKUP_FILE and restarted the app."
