# Render builds this image and runs it with $PORT set; FrankenPHP serves public/.
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql bcmath gd intl zip opcache \
    && printf '%s\n' 'memory_limit=256M' 'upload_max_filesize=4M' 'post_max_size=8M' 'opcache.enable=1' 'opcache.validate_timestamps=0' \
       > /usr/local/etc/php/conf.d/zz-app.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .

# A throwaway key lets artisan boot during the build; Render supplies the real APP_KEY.
RUN set -eu; \
    export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"; \
    mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache; \
    composer dump-autoload --no-dev --optimize; \
    php artisan filament:assets; \
    chmod -R 775 storage bootstrap/cache; \
    cp /usr/local/bin/frankenphp /tmp/frankenphp && mv -f /tmp/frankenphp /usr/local/bin/frankenphp && chmod +x /usr/local/bin/frankenphp \
    && chmod +x docker/start.sh

CMD ["docker/start.sh"]
