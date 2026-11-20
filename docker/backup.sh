#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

cd "$(dirname "$0")/.."
app_env=${APP_ENV_FILE:-.env.prod}
release_env=${1:-release.env}
backup_dir=${2:-backups/$(date -u +%Y%m%dT%H%M%SZ)-$$}
dc() { env -u APP_IMAGE -u WEB_IMAGE docker compose --env-file "$app_env" --env-file "$release_env" -f compose.prod.yaml "$@"; }
mkdir -p "$(dirname "$backup_dir")"
mkdir "$backup_dir"
# Called with HTTP in maintenance mode and workers stopped by deploy.sh.
# A manual live backup is only a DB transaction snapshot, not a cross-store snapshot.
dc exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --single-transaction --no-tablespaces --set-gtid-purged=OFF -u"$MYSQL_USER" "$MYSQL_DATABASE"' > "$backup_dir/database.sql.partial"
mv "$backup_dir/database.sql.partial" "$backup_dir/database.sql"
dc run --rm --no-deps --entrypoint tar app -czf - -C /var/www/html/storage . > "$backup_dir/storage.tar.gz.partial"
mv "$backup_dir/storage.tar.gz.partial" "$backup_dir/storage.tar.gz"
cp "$release_env" "$backup_dir/release.env"
cp "$app_env" "$backup_dir/app.env"
printf 'Backup saved: %s\n' "$backup_dir"
