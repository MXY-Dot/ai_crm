FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
ARG VITE_APP_NAME="WERO"
ENV VITE_APP_NAME=${VITE_APP_NAME}
RUN npm run build


FROM php:8.5-fpm-bookworm AS app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        nginx \
        postgresql-client \
        supervisor \
        unzip \
    && docker-php-ext-install bcmath intl mbstring opcache pcntl pdo_pgsql pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove git \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ARG INSTALL_DEV=false
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/crm-entrypoint
COPY docker/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN if [ "$INSTALL_DEV" = "true" ]; then composer install --no-interaction --prefer-dist; else composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; fi \
    && chmod +x /usr/local/bin/crm-entrypoint \
    && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["crm-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
