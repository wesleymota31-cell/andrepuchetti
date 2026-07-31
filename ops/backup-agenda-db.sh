#!/usr/bin/env bash
set -euo pipefail

CONFIG="/home/u791815916/agenda_config.php"
BACKUP_DIR="/home/u791815916/agenda-db-backups"
RETENTION_DAYS="14"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

CONFIG_TMP="$(mktemp)"
php -r '
require "/home/u791815916/agenda_config.php";
foreach (["host", "dbname", "username", "password"] as $key) {
    if (!isset($$key) || $$key === "") {
        fwrite(STDERR, "Missing database config: {$key}\n");
        exit(1);
    }
    echo $$key . PHP_EOL;
}
' > "$CONFIG_TMP"

DB_HOST="$(sed -n '1p' "$CONFIG_TMP")"
DB_NAME="$(sed -n '2p' "$CONFIG_TMP")"
DB_USER="$(sed -n '3p' "$CONFIG_TMP")"
DB_PASS="$(sed -n '4p' "$CONFIG_TMP")"
rm -f "$CONFIG_TMP"

TIMESTAMP="$(date '+%Y-%m-%d_%H-%M-%S')"
SQL_FILE="${BACKUP_DIR}/agenda_${TIMESTAMP}.sql"
GZ_FILE="${SQL_FILE}.gz"
DEFAULTS_FILE="$(mktemp)"

cleanup() {
  rm -f "$DEFAULTS_FILE" "$SQL_FILE"
}
trap cleanup EXIT

chmod 600 "$DEFAULTS_FILE"
cat > "$DEFAULTS_FILE" <<MYSQL_DEFAULTS
[client]
password="${DB_PASS}"
MYSQL_DEFAULTS

mysqldump \
  --defaults-extra-file="$DEFAULTS_FILE" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --default-character-set=utf8mb4 \
  -h"$DB_HOST" \
  -u"$DB_USER" \
  "$DB_NAME" > "$SQL_FILE"

gzip -f "$SQL_FILE"
chmod 600 "$GZ_FILE"

find "$BACKUP_DIR" -type f -name 'agenda_*.sql.gz' -mtime +"$RETENTION_DAYS" -delete

echo "Backup created: $GZ_FILE"
