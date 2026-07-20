#!/bin/bash
set -e

# Generate .env from environment variables for Laravel optimizations
if [ ! -f /var/www/html/.env ]; then
    echo "APP_KEY=${APP_KEY:-}" > /var/www/html/.env
    echo "APP_ENV=${APP_ENV:-production}" >> /var/www/html/.env
    echo "APP_DEBUG=${APP_DEBUG:-false}" >> /var/www/html/.env
    echo "APP_URL=${APP_URL:-}" >> /var/www/html/.env
    echo "DB_CONNECTION=${DB_CONNECTION:-mysql}" >> /var/www/html/.env
    echo "DB_HOST=${DB_HOST:-mysql}" >> /var/www/html/.env
    echo "DB_PORT=${DB_PORT:-3306}" >> /var/www/html/.env
    echo "DB_DATABASE=${DB_DATABASE:-aihd_lab}" >> /var/www/html/.env
    echo "DB_USERNAME=${DB_USERNAME:-aihd}" >> /var/www/html/.env
    echo "DB_PASSWORD=${DB_PASSWORD:-aihd}" >> /var/www/html/.env
    echo "REDIS_HOST=${REDIS_HOST:-redis}" >> /var/www/html/.env
    echo "REDIS_PORT=${REDIS_PORT:-6379}" >> /var/www/html/.env
    echo "LOG_CHANNEL=${LOG_CHANNEL:-stack}" >> /var/www/html/.env
    echo "LOG_LEVEL=${LOG_LEVEL:-warning}" >> /var/www/html/.env
    echo "CACHE_STORE=${CACHE_STORE:-redis}" >> /var/www/html/.env
    echo "SESSION_DRIVER=${SESSION_DRIVER:-redis}" >> /var/www/html/.env
    echo "QUEUE_CONNECTION=${QUEUE_CONNECTION:-redis}" >> /var/www/html/.env
    chown www-data:www-data /var/www/html/.env
fi

# Run Laravel optimizations at runtime
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link --force

# Start supervisord
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf