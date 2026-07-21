#!/bin/bash
set -e

# Always regenerate .env from container environment so deploy-time compose
# variables (LAB_AUTH_MODE, CORE_BASE_URL, …) take effect without a manual
# volume wipe.
cat > /var/www/html/.env <<EOF
APP_KEY=${APP_KEY:-}
APP_ENV=${APP_ENV:-production}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL:-}
DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_HOST:-mysql}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-aihd_lab}
DB_USERNAME=${DB_USERNAME:-aihd}
DB_PASSWORD=${DB_PASSWORD:-aihd}
REDIS_HOST=${REDIS_HOST:-redis}
REDIS_PORT=${REDIS_PORT:-6379}
LOG_CHANNEL=${LOG_CHANNEL:-stack}
LOG_LEVEL=${LOG_LEVEL:-warning}
CACHE_STORE=${CACHE_STORE:-redis}
SESSION_DRIVER=${SESSION_DRIVER:-redis}
QUEUE_CONNECTION=${QUEUE_CONNECTION:-redis}
LAB_AUTH_MODE=${LAB_AUTH_MODE:-anonymous}
LAB_BILLING_ENABLED=${LAB_BILLING_ENABLED:-false}
CORE_BASE_URL=${CORE_BASE_URL:-}
CORE_SERVICE_CREDENTIAL=${CORE_SERVICE_CREDENTIAL:-}
CORS_ALLOWED_ORIGINS=${CORS_ALLOWED_ORIGINS:-}
LOG_VIEWER_ENABLED=${LOG_VIEWER_ENABLED:-true}
EOF
chown www-data:www-data /var/www/html/.env

# Run Laravel optimizations at runtime
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link --force

# Start supervisord
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
