# syntax=docker/dockerfile:1
FROM php:8.4-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev libfreetype6-dev libicu-dev libjpeg62-turbo-dev \
        libonig-dev libpng-dev libxml2-dev libzip-dev libfcgi-bin unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath calendar curl gd intl mbstring pcntl pdo_mysql zip opcache \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-production.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/crm-entrypoint
COPY --chmod=755 docker/scheduler.sh /usr/local/bin/crm-scheduler

FROM php-base AS source
COPY . .
# Never carry workstation caches or development-server markers into an image.
RUN rm -f bootstrap/cache/*.php public/hot public/*-vite.hot \
    && mkdir -p storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && rm -rf public/storage \
    && ln -s /var/www/html/storage/app/public public/storage

FROM source AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --classmap-authoritative \
    && composer check-platform-reqs --no-dev

FROM node:22-bookworm-slim AS assets
WORKDIR /var/www/html
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
COPY . .
RUN npm ci --no-audit --no-fund && npm run build \
    && npm --prefix packages/Webkul/Admin ci --no-audit --no-fund \
    && npm --prefix packages/Webkul/Admin run build \
    && npm --prefix packages/Webkul/Installer ci --no-audit --no-fund \
    && npm --prefix packages/Webkul/Installer run build \
    && npm --prefix packages/Webkul/WebForm ci --no-audit --no-fund \
    && npm --prefix packages/Webkul/WebForm run build \
    && rm -f public/hot public/*-vite.hot

FROM php-base AS app
ENV APP_ENV=production APP_DEBUG=false
COPY --from=dependencies --chown=root:root /var/www/html /var/www/html
COPY --from=assets --chown=root:root /var/www/html/public /var/www/html/public
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u+rwX storage bootstrap/cache
USER www-data
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1
ENTRYPOINT ["crm-entrypoint"]
CMD ["php-fpm", "-F"]

FROM app AS test
USER root
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV APP_ENV=testing APP_DEBUG=true COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
    && composer check-platform-reqs \
    && mkdir -p vendor/pestphp/pest-plugin-mutate/.temp \
    && chown www-data:www-data vendor/pestphp/pest-plugin-mutate/.temp \
    && touch .env
USER www-data
HEALTHCHECK NONE
CMD ["php", "artisan", "test", "--compact", "--cache-directory=/var/www/html/storage/framework/testing/phpunit"]

FROM nginx:1.28-alpine AS web
RUN if id www-data >/dev/null 2>&1; then deluser www-data; fi \
    && if grep -q '^www-data:' /etc/group; then delgroup www-data; fi \
    && addgroup -g 33 -S www-data \
    && adduser -u 33 -S -D -H -G www-data www-data
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY --from=app /var/www/html/public /var/www/html/public
RUN mkdir -p /var/www/html/storage/app/public \
    && chown -R www-data:www-data /var/www/html/storage
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1
ENTRYPOINT ["nginx"]
CMD ["-g", "daemon off;"]
