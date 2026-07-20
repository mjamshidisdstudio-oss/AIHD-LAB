FROM php:8.2-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    curl \
    git \
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

FROM base AS app

COPY --chown=www-data:www-data . .

RUN composer install --no-interaction --no-dev --optimize-autoloader \
    && mkdir -p storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/private \
        storage/app/media \
        storage/app/public \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

FROM node:20-slim AS frontend

WORKDIR /app
COPY --from=app /var/www/html /app
RUN npm install && npm run build && rm -rf node_modules

FROM php:8.2-fpm-bookworm AS final

# Install only runtime deps (no build deps) + nginx + supervisor
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy PHP + extensions from base (already compiled, no rebuild needed)
COPY --from=base /usr/local /usr/local
COPY --from=base /usr/lib/php /usr/lib/php
COPY --from=base /etc/php /etc/php

WORKDIR /var/www/html

COPY --from=frontend --chown=www-data:www-data /app .

RUN rm -f /etc/nginx/sites-enabled/default \
    && mkdir -p /var/log/nginx /var/run/php \
    && chown -R www-data:www-data /var/log/nginx /var/run/php

COPY infra/docker/nginx/default.conf /etc/nginx/nginx.conf
COPY infra/docker/nginx/php-fpm.conf /etc/nginx/conf.d/php-fpm.conf
COPY infra/docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY infra/docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -f http://localhost:8000/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]