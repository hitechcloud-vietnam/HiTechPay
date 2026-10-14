#!/usr/bin/env bash
# PostgreSQL backup of the docker compose stack (custom format, restorable with restore-db.sh).
#
#   BACKUP_DIR=/var/backups/hitechpay KEEP_DAYS=14 scripts/backup-db.sh
# Optional: S3_URI=s3://bucket/hitechpay (needs the aws cli) to copy each dump off the server.
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
BACKUP_DIR="${BACKUP_DIR:-$ROOT_DIR/backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"
ENV_FILE="${ENV_FILE:-deploy/.env}"
# shellcheck source=/dev/null
[[ -f "$ENV_FILE" ]] && set -a && source "$ENV_FILE" && set +a
DB_USER="${HTP_DB_USER:-db_user}"; DB_NAME="${HTP_DB_NAME:-hyperswitch_db}"
COMPOSE="$(cat deploy/.compose-cmd 2>/dev/null || echo docker compose -f deploy/docker-compose.yml)"

mkdir -p "$BACKUP_DIR"
file="$BACKUP_DIR/db-$(date +%Y%m%d-%H%M%S).dump"
$COMPOSE --env-file "$ENV_FILE" exec -T pg pg_dump -U "$DB_USER" -Fc "$DB_NAME" > "$file.partial"
mv "$file.partial" "$file"
echo "backup: $file ($(du -h "$file" | cut -f1))"

if [[ -n "${S3_URI:-}" ]]; then
  aws s3 cp "$file" "$S3_URI/$(basename "$file")"
fi
find "$BACKUP_DIR" -name 'db-*.dump' -mtime "+$KEEP_DAYS" -delete
