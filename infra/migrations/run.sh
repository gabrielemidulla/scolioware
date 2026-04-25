#!/usr/bin/env bash
# Applies /sql/*.sql in version order once each (tracked in schema_migrations).
set -euo pipefail

export MYSQL_PWD="${MYSQL_PASSWORD:-}"

HOST="${MYSQL_HOST:-mysql}"
PORT="${MYSQL_PORT:-3306}"
USER="${MYSQL_USER:?MYSQL_USER required}"
DB="${MYSQL_DATABASE:?MYSQL_DATABASE required}"

mysql_cli=(mysql -h"$HOST" -P"$PORT" -u"$USER" "$DB")

echo "migrate: waiting for MySQL at ${HOST}:${PORT} ..."
for _ in $(seq 1 60); do
  if "${mysql_cli[@]}" -e "SELECT 1" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
if ! "${mysql_cli[@]}" -e "SELECT 1" >/dev/null 2>&1; then
  echo "migrate: could not connect to MySQL" >&2
  exit 1
fi

echo "migrate: ensuring schema_migrations table exists ..."
"${mysql_cli[@]}" <<'EOSQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOSQL

shopt -s nullglob
_files=(/sql/*.sql)
if [[ ${#_files[@]} -eq 0 ]]; then
  echo "migrate: no SQL files in /sql — nothing to do"
  exit 0
fi

while IFS= read -r f; do
  [[ -n "$f" ]] || continue
  base=$(basename "$f")
  version="${base%.sql}"
  if ! [[ "$version" =~ ^[0-9]{3}_[a-zA-Z0-9_]+$ ]]; then
    echo "migrate: skip (expected NNN_name.sql): $base" >&2
    continue
  fi
  count=$("${mysql_cli[@]}" -N -e "SELECT COUNT(*) FROM schema_migrations WHERE version='${version}'")
  if [[ "${count// /}" == "1" ]]; then
    echo "migrate: already applied $version"
    continue
  fi
  echo "migrate: applying $version ..."
  "${mysql_cli[@]}" <"$f"
  "${mysql_cli[@]}" -e "INSERT INTO schema_migrations (version, name) VALUES ('${version}', '${version}')"
  echo "migrate: applied $version"
done < <(printf '%s\n' "${_files[@]}" | sort -V)

echo "migrate: finished OK"
exit 0
