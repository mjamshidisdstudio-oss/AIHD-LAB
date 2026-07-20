#!/bin/bash
set -euo pipefail

# ──────────────────────────────────────────────────────────
# AIHD-Lab CI/CD Deploy Script
# Runs on the target server (showroom-germany) via SSH.
# Pulls code, builds Docker images, restarts containers,
# runs migrations, and verifies health.
# ──────────────────────────────────────────────────────────

DEPLOY_DIR="${DEPLOY_DIR:-/opt/aihd-lab}"
COMPOSE_PROJECT="aihd-lab"
COMPOSE_FILE="docker-compose.yml"

cd "$DEPLOY_DIR"

echo "=== 1. Pull latest code ==="
git fetch origin
git reset --hard origin/main

# Fix CRLF if any crept in
find infra/ -name '*.sh' -o -name '*.conf' | xargs -I{} sed -i 's/\r$//' {} 2>/dev/null || true

echo "=== 2. Build Docker images ==="
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" build app marketplace 2>&1

echo "=== 3. Start stateful services (if not running) ==="
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" up -d mysql redis 2>&1

echo "=== 4. Wait for MySQL ==="
for i in $(seq 1 30); do
    if docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" exec -T mysql \
        mysqladmin ping -h127.0.0.1 -uroot -p"${MYSQL_ROOT_PASSWORD:-root}" 2>/dev/null; then
        echo "MySQL is ready!"
        break
    fi
    if [ "$i" -eq 30 ]; then
        echo "ERROR: MySQL did not become ready in time"
        exit 1
    fi
    sleep 2
done

echo "=== 5. Wait for Redis ==="
for i in $(seq 1 15); do
    if docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" exec -T redis \
        redis-cli ping 2>/dev/null | grep -q PONG; then
        echo "Redis is ready!"
        break
    fi
    if [ "$i" -eq 15 ]; then
        echo "ERROR: Redis did not become ready in time"
        exit 1
    fi
    sleep 2
done

echo "=== 6. Start app containers ==="
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" up -d app marketplace 2>&1

echo "=== 7. Wait for app health ==="
for i in $(seq 1 30); do
    STATUS=$(docker inspect --format='{{.State.Health.Status}}' aihd-app 2>/dev/null || echo "starting")
    echo "  health check $i/30: $STATUS"
    if [ "$STATUS" = "healthy" ]; then
        echo "App is healthy!"
        break
    fi
    if [ "$STATUS" = "unhealthy" ] && [ "$i" -gt 20 ]; then
        echo "WARNING: App is unhealthy, checking logs..."
        docker logs aihd-app --tail 20 2>&1
        break
    fi
    sleep 3
done

echo "=== 8. Run migrations ==="
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" exec -T app php artisan migrate --force 2>&1

echo "=== 9. Fix storage permissions ==="
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" exec -u 0 app \
    chown -R www-data:www-data /var/www/html/storage 2>&1

echo "=== 10. Verify health ==="
HEALTH_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/ || echo "failed")
echo "Backend health: $HEALTH_CODE"

MARKET_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:3100/ || echo "failed")
echo "Marketplace health: $MARKET_CODE"

echo ""
echo "=== Deploy complete ==="
echo "Backend:  http://localhost:8080 → https://api.revivoto.ai"
echo "Frontend: http://localhost:3100 → https://app.revivoto.ai"