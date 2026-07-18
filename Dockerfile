FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
ARG VITE_APP_NAME=Laravel
ENV VITE_APP_NAME=${VITE_APP_NAME}
RUN npm run build

FROM php:8.4-cli AS app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
        git \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install bcmath curl intl mbstring opcache pcntl pdo_mysql pdo_pgsql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ARG INSTALL_DEV=false
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/crm-entrypoint

RUN if [ "$INSTALL_DEV" = "true" ]; then composer install --no-interaction --prefer-dist; else composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; fi \
    && chmod +x /usr/local/bin/crm-entrypoint \
    && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["crm-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]