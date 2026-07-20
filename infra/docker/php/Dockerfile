FROM node:20-slim AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources/ resources/
COPY vite.config.js .
RUN npm run build

FROM php:8.2-fpm-bookworm AS backend

# System deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx supervisor curl unzip git \
    libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath ctype exif fileinfo gd mbstring mysqli \
        pdo pdo_mysql pcntl zip xml dom xmlwriter \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Composer deps
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --optimize-autoloader

# App source
COPY --chown=www-data:www-data . .

# Vite build output
COPY --from=frontend /app/public/build /var/www/html/public/build

# Storage dirs
RUN mkdir -p storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/private \
        storage/app/media \
        storage/app/public \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Nginx + supervisor
RUN rm -f /etc/nginx/sites-enabled/default \
    && mkdir -p /var/log/nginx /var/run/php

COPY infra/docker/nginx/default.conf /etc/nginx/nginx.conf
COPY infra/docker/nginx/php-fpm.conf /etc/nginx/conf.d/php-fpm.conf
COPY infra/docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY infra/docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -f http://localhost:8000/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]