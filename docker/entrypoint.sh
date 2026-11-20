#!/bin/sh
set -eu

cd /var/www/html
umask 0022

mkdir -p storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# This link lives in the immutable image; only its target is a shared volume.
if [ ! -L public/storage ] || [ "$(readlink public/storage)" != /var/www/html/storage/app/public ]; then
    echo 'Expected public/storage to link to /var/www/html/storage/app/public.' >&2
    exit 1
fi

# Rebuild manifests using the installed dependencies, without Composer scripts,
# database changes, key generation, or build-time environment configuration.
if [ ! -f bootstrap/cache/packages.php ]; then
    php artisan package:discover --ansi
fi

case "${1:-}" in
    php-fpm|php-fpm8.4|crm-scheduler)
        php artisan config:cache --ansi
        ;;
    php)
        if [ "${2:-}" = artisan ] && [ "${3:-}" = queue:work ]; then
            php artisan config:cache --ansi
        fi
        ;;
esac

exec "$@"
