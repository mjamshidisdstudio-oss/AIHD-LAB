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

# Ensure launch-mode flags are set on the host .env used by docker compose.
ensure_launch_mode_env() {
    local env_file="${DEPLOY_DIR}/.env"
    touch "$env_file"
    for kv in \
        "LAB_AUTH_MODE=anonymous" \
        "LAB_BILLING_ENABLED=false" \
        "NUXT_PUBLIC_AUTH_MODE=anonymous" \
        "LOG_VIEWER_ENABLED=true"
    do
        key="${kv%%=*}"
        if grep -q "^${key}=" "$env_file" 2>/dev/null; then
            sed -i "s|^${key}=.*|${kv}|" "$env_file"
        else
            echo "$kv" >> "$env_file"
        fi
    done
}

ensure_launch_mode_env

echo "=== 1. Pull latest code ==="
git fetch origin
git reset --hard origin/main

# Fix CRLF if any crept in
find infra/ -name '*.sh' -o -name '*.conf' | xargs -I{} sed -i 's/\r$//' {} 2>/dev/null || true

echo "=== 2. Build Docker images ==="
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" build app marketplace admin 2>&1

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
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" up -d --force-recreate app marketplace admin 2>&1

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

echo "=== 8a. Publish Log Viewer assets ==="
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" exec -T app php artisan log-viewer:publish 2>&1 || true

echo "=== 8b. Ensure admin user ==="
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" exec -T app php artisan db:seed --class=AdminUserSeeder --force 2>&1

echo "=== 9. Fix storage permissions ==="
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" exec -u 0 app \
    chown -R www-data:www-data /var/www/html/storage 2>&1

echo "=== 10. Verify health ==="
HEALTH_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/ || echo "failed")
echo "Backend health: $HEALTH_CODE"

SERVICES_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/api/marketplace/services || echo "failed")
echo "Marketplace services API: $SERVICES_CODE"
if [ "$SERVICES_CODE" != "200" ]; then
    echo "ERROR: /api/marketplace/services returned $SERVICES_CODE (expected 200)"
    docker logs aihd-app --tail 30 2>&1 || true
    exit 1
fi

MARKET_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:3100/ || echo "failed")
echo "Marketplace health: $MARKET_CODE"

ADMIN_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:3200/login || echo "failed")
echo "Admin health: $ADMIN_CODE"

# Sync host nginx when this script runs on the production server
if [ -f infra/nginx/aihd-lab-https.conf ] && [ -d /etc/nginx/sites-available ]; then
    echo "=== 11. Update host nginx ==="
    cp infra/nginx/aihd-lab-https.conf /etc/nginx/sites-available/revivoto.ai
    cp infra/nginx/aihd-lab-https.conf /etc/nginx/sites-enabled/revivoto.ai
    nginx -t && systemctl reload nginx
fi

echo ""
echo "=== Deploy complete ==="
echo "Backend:  http://localhost:8080 → https://api.revivoto.ai"
echo "Frontend: http://localhost:3100 → https://app.revivoto.ai"
echo "Admin:    http://localhost:3200 → https://admin.revivoto.ai/login"