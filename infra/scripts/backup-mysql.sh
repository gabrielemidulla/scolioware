#!/usr/bin/env bash
# Nightly (or on-demand) MySQL backup: run on a host with docker and access to the mysql service.
# Example (stack already up, from repo root):
#   export MYSQL_PWD=app_password
#   ./infra/scripts/backup-mysql.sh
set -euo pipefail
OUT_DIR="${1:-.}"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
HOST="${MYSQL_HOST:-127.0.0.1}"
PORT="${MYSQL_PORT:-3306}"
USER="${MYSQL_USER:-app_user}"
DB="${MYSQL_DATABASE:-php_commerce}"
mkdir -p "$OUT_DIR"
F="$OUT_DIR/${DB}-backup-$STAMP.sql.gz"
mysqldump -h "$HOST" -P "$PORT" -u "$USER" "$DB" | gzip -c > "$F"
echo "Wrote $F"
