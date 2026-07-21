#!/bin/bash
set -euo pipefail

cd /opt/aihd-lab

# Create .env if not exists
if [ ! -f .env ]; then
    cp .env.example .env
    # Generate random APP_KEY
    APP_KEY=$(php -r "echo 'base64:' . base64_encode(random_bytes(32));")
    sed -i "s|APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
    echo "APP_ENV=production" >> .env
    echo "APP_DEBUG=false" >> .env
    echo "LOG_LEVEL=warning" >> .env
    echo "LAB_AUTH_MODE=anonymous" >> .env
    echo "LAB_BILLING_ENABLED=false" >> .env
    echo "LOG_VIEWER_ENABLED=true" >> .env
fi

# Pull base images
docker compose -p aihd-lab -f docker-compose.yml pull mysql redis

# Build app images
docker compose -p aihd-lab -f docker-compose.yml build app marketplace admin

# Start stateful services
docker compose -p aihd-lab -f docker-compose.yml up -d mysql redis

# Wait for MySQL
echo "Waiting for MySQL..."
for i in $(seq 1 30); do
    if docker compose -p aihd-lab exec -T mysql mysqladmin ping -h127.0.0.1 -uroot -proot 2>/dev/null; then
        echo "MySQL is ready!"
        break
    fi
    sleep 2
done

# Wait for Redis
echo "Waiting for Redis..."
for i in $(seq 1 15); do
    if docker compose -p aihd-lab exec -T redis redis-cli ping 2>/dev/null | grep -q PONG; then
        echo "Redis is ready!"
        break
    fi
    sleep 2
done

# Run migrations
docker compose -p aihd-lab -f docker-compose.yml run --rm app php artisan migrate --force

# Seed if fresh
echo "Checking if seeding is needed..."
SEEDED=$(docker compose -p aihd-lab -f docker-compose.yml run --rm app php artisan tinker --execute="echo App\\Models\\Service::count();" 2>/dev/null || echo "0")
if [ "$SEEDED" = "0" ]; then
    echo "Empty database — seeding..."
    docker compose -p aihd-lab -f docker-compose.yml run --rm app php artisan db:seed --force
else
    echo "Database already seeded — skipping"
fi

# Start app containers
docker compose -p aihd-lab -f docker-compose.yml up -d app marketplace admin

# Fix storage permissions
docker compose -p aihd-lab exec -u 0 app chown -R www-data:www-data /var/www/html/storage

echo "=== Deploy complete ==="
echo "Backend:  http://127.0.0.1:8080"
echo "Frontend: http://127.0.0.1:3100"
echo "Admin:    http://127.0.0.1:3200"
echo ""
echo "Check health:"
echo "  curl -f http://127.0.0.1:8080/up"
echo ""
echo "Remaining steps:"
echo "  1. Setup nginx site config (see infra/nginx/aihd-lab-https.conf)"
echo "  2. Obtain SSL cert with certbot"
echo "  3. Configure DNS A record"