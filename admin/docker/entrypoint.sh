#!/bin/sh
set -e
cd /var/www/html

# Copy built public assets (Filament, styles) into the volume served by nginx.
if [ -d /var/www/public-shared ]; then
    cp -a public/. /var/www/public-shared/
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php artisan migrate --force
    # demo data (users, registry template, demo book) once, on an empty database, and only with a real password
    if [ ! -f storage/app/.seeded ]; then
        if [ -n "${ADMIN_PASSWORD:-}" ]; then
            php artisan db:seed --force && touch storage/app/.seeded
        else
            echo "ADMIN_PASSWORD is empty: demo users are not created"
        fi
    fi
    # demo users always get the current ADMIN_PASSWORD (the secret may change after the first seed)
    php artisan books:demo-password || true
    # скани в постійному томі + зразки реальних сканів (див. app/Console/Commands/InitStorage.php)
    # одноразово: прибрати тестові дані, залишити лише реальні книги (KEEP_BOOKS, через «|»)
    # у підоболонці, щоб не зіпсувати "$@" (команду контейнера для exec нижче)
    ( IFS='|'; set -- ${KEEP_BOOKS:-Test2}; php artisan books:keep-only "$@" ) || true
    # довідники з тезаурусів РМФУ: класифікації, матеріали, техніки, способи надходження (app/Console/Commands/ImportThesauri.php)
    php artisan books:thesauri || true
    php artisan books:storage-init || true
    php artisan config:cache && php artisan route:cache && php artisan view:cache
fi
# artisan above runs as root; php-fpm (www-data) must be able to write the caches, logs, thumbnails and exports
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
