#!/bin/sh
set -e
cd /var/www/html

# Copy built public assets (Filament, styles) into the volume served by nginx.
if [ -d /var/www/public-shared ]; then
    cp -a public/. /var/www/public-shared/
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php artisan migrate --force
    # demo data (users, description schema, demo case) once, on an empty database, and only with a real password
    if [ ! -f storage/app/.seeded ]; then
        if [ -n "${ADMIN_PASSWORD:-}" ]; then
            php artisan db:seed --force && touch storage/app/.seeded
        else
            echo "ADMIN_PASSWORD is empty: demo users are not created"
        fi
    fi
    # demo users always get the current ADMIN_PASSWORD (the secret may change after the first seed)
    php artisan archive:demo-password || true
    # скани в постійному томі (app/Console/Commands/InitStorage.php)
    php artisan archive:storage-init || true
    # 10 документів розділу «Міжнародні багатосторонні і двосторонні переговори…» еАрхіву МЗС (один раз)
    if [ -f storage/app/.seeded ] && [ ! -f storage/app/.earchive-imported ]; then
        php artisan archive:import-earchive 2 --ids=167,249,474,493,494,598,648,667,692,848 --recognized \
            && touch storage/app/.earchive-imported || true
    fi
    # увесь каталог еАрхіву МЗС (1014 документів, метадані без сканів; лише відсутні, один раз)
    if [ -f storage/app/.seeded ] && [ ! -f storage/app/.earchive-catalog ]; then
        php artisan archive:import-earchive-catalog && touch storage/app/.earchive-catalog || true
    fi
    php artisan config:cache && php artisan route:cache && php artisan view:cache
fi
# artisan above runs as root; php-fpm (www-data) must be able to write the caches, logs, thumbnails and exports
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
