#!/usr/bin/env bash
# Restore a dump made by backup-db.sh into the docker compose PostgreSQL.
# Stops router + schedulers during the restore, then starts them again.
#
#   scripts/restore-db.sh backups/db-20261014-021500.dump
set -euo pipefail
dump="${1:?usage: scripts/restore-db.sh <file.dump>}"
[[ -f "$dump" ]] || { echo "no such file: $dump" >&2; exit 1; }
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dump="$(cd "$(dirname "$dump")" && pwd)/$(basename "$dump")"
cd "$ROOT_DIR"
ENV_FILE="${ENV_FILE:-deploy/.env}"
# shellcheck source=/dev/null
[[ -f "$ENV_FILE" ]] && set -a && source "$ENV_FILE" && set +a
DB_USER="${HTP_DB_USER:-db_user}"; DB_NAME="${HTP_DB_NAME:-hyperswitch_db}"
COMPOSE="$(cat deploy/.compose-cmd 2>/dev/null || echo docker compose -f deploy/docker-compose.yml)"

read -r -p "Restore $dump into $DB_NAME? This overwrites current data [y/N] " answer
[[ "$answer" == "y" || "$answer" == "Y" ]] || exit 1

$COMPOSE --env-file "$ENV_FILE" --profile scheduler stop router scheduler-consumer scheduler-producer
$COMPOSE --env-file "$ENV_FILE" exec -T pg pg_restore -U "$DB_USER" -d "$DB_NAME" --clean --if-exists --no-owner < "$dump"
$COMPOSE --env-file "$ENV_FILE" --profile scheduler up -d
echo "restored $dump"
