#!/bin/sh
# «Архів» на Jam-сервері (78.27.234.124: Ubuntu 24.04, nginx + PHP 8.4-FPM + MySQL 8, без Docker).
# Запуск від root: DOMAIN=arch.emuseum.ua ADMIN_PASSWORD=... sh /var/www/archive/deploy/jam/setup.sh
# Повторний запуск оновлює код і нічого не стирає: .env, база, скани й PDF лишаються.
# Чужих сайтів не чіпає: лише /var/www/archive, база archive, /etc/nginx/sites-*/archive, cron і pm2-процес archive-queue.
set -eu
DOMAIN="${DOMAIN:-arch.emuseum.ua}"
APP=/var/www/archive
REPO=https://github.com/emuseum-code/earchive-platform.git
PHP=php8.4

# 1. пакети: розширення PHP і pdftoppm (лише ті, яких бракує)
need=""
for p in $PHP-fpm $PHP-cli $PHP-mysql $PHP-intl $PHP-zip $PHP-gd $PHP-bcmath $PHP-mbstring $PHP-xml $PHP-curl poppler-utils git unzip; do
  dpkg -s "$p" >/dev/null 2>&1 || need="$need $p"
done
if [ -n "$need" ]; then apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq $need; fi
command -v composer >/dev/null || { curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer; }

# 2. код
if [ -d "$APP/.git" ]; then git -C "$APP" pull --ff-only; else git clone --depth 1 "$REPO" "$APP"; fi
cd "$APP/admin"

# 3. база MySQL і .env (створюються один раз)
if [ ! -f .env ]; then
  DBPW="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
  mysql -e "CREATE DATABASE IF NOT EXISTS archive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'archive'@'localhost' IDENTIFIED BY '$DBPW';
    ALTER USER 'archive'@'localhost' IDENTIFIED BY '$DBPW';
    GRANT ALL ON archive.* TO 'archive'@'localhost'; FLUSH PRIVILEGES;"
  sed -e "s|^APP_KEY=.*|APP_KEY=base64:$(head -c 32 /dev/urandom | base64)|" \
      -e "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" \
      -e "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" -e "s|^DB_HOST=.*|DB_HOST=127.0.0.1|" \
      -e "s|^DB_PORT=.*|DB_PORT=3306|" -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DBPW|" .env.docker.example > .env
  chmod 640 .env
fi

# 4. залежності й Laravel
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader -q
mkdir -p storage/app/public storage/app/private storage/app/earchive-files storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan filament:assets -q
# ліміти PHP-FPM лише для цього сайту (як admin/docker/php.ini)
printf 'memory_limit=512M\nupload_max_filesize=50M\npost_max_size=60M\nmax_execution_time=300\n' > public/.user.ini
php artisan storage:link -q 2>/dev/null || true
php artisan migrate --force
if [ ! -f storage/app/.seeded ]; then
  if [ -n "${ADMIN_PASSWORD:-}" ]; then php artisan db:seed --force && touch storage/app/.seeded
  else echo "ADMIN_PASSWORD порожній: демо-користувачів не створено"; fi
fi
[ -n "${ADMIN_PASSWORD:-}" ] && ADMIN_PASSWORD="$ADMIN_PASSWORD" php artisan archive:demo-password || true
php artisan archive:storage-init || true
if [ -f storage/app/.seeded ] && [ ! -f storage/app/.earchive-catalog ]; then
  php artisan archive:import-earchive-catalog && touch storage/app/.earchive-catalog || true
fi
php artisan config:cache -q && php artisan route:cache -q && php artisan view:cache -q
chown -R www-data:www-data storage bootstrap/cache

# 5. черга (pm2, від www-data) і планувальник (cron)
if command -v pm2 >/dev/null; then
  pm2 describe archive-queue >/dev/null 2>&1 && pm2 restart archive-queue --update-env >/dev/null || \
    pm2 start "sudo -u www-data php $APP/admin/artisan queue:work --queue=interactive,default --timeout=600 --max-time=3600" --name archive-queue >/dev/null
  pm2 save >/dev/null
fi
CRON="* * * * * cd $APP/admin && php artisan schedule:run >/dev/null 2>&1"
( crontab -u www-data -l 2>/dev/null | grep -v "$APP/admin" ; echo "$CRON" ) | crontab -u www-data -

# 6. nginx + сертифікат
SOCK="$(ls /run/php/$PHP-fpm.sock 2>/dev/null || ls /run/php/php*-fpm.sock | head -1)"
if [ ! -f /etc/nginx/sites-available/archive ]; then
  sed -e "s|ARCHIVE_DOMAIN|$DOMAIN|g" -e "s|ARCHIVE_ROOT|$APP/admin/public|g" -e "s|ARCHIVE_SOCK|$SOCK|g" \
    "$APP/deploy/jam/nginx.conf" > /etc/nginx/sites-available/archive
  ln -sf /etc/nginx/sites-available/archive /etc/nginx/sites-enabled/archive
  nginx -t && systemctl reload nginx
  command -v certbot >/dev/null && certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect --register-unsafely-without-email || \
    echo "certbot не спрацював: перевірте DNS $DOMAIN"
else
  nginx -t && systemctl reload nginx
fi

echo "https://$DOMAIN/admin: $(curl -s -o /dev/null -w '%{http_code}' "https://$DOMAIN/admin/login")"
