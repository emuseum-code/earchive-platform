# Облік інвентарних книг: Laravel 13 + Filament 5 (PHP-FPM)
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libpq-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd bcmath opcache exif \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/books.ini

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
# storage/* is not in git, so the folders Laravel needs at boot are created before artisan runs
RUN rm -f public/storage && mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && php artisan filament:assets \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/books-entrypoint
RUN chmod +x /usr/local/bin/books-entrypoint
ENTRYPOINT ["books-entrypoint"]
CMD ["php-fpm"]
