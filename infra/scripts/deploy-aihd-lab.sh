#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# AIHD Lab — Deploy Script
# Usage: bash infra/scripts/deploy-aihd-lab.sh [--source PATH]
#
# Dependencies (on target server): docker, docker compose, git, certbot, nginx
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

# ── Config ──────────────────────────────────────────────────────────────────
DOMAIN="${AIHD_DOMAIN:-aihd-lab.example.com}"
COMPOSE_PROJECT="aihd-lab"
DEPLOY_PATH="${AIHD_DEPLOY_PATH:-/opt/aihd-lab}"
COMPOSE_FILE="docker-compose.yml"
SOURCE_DIR="${1:-$REPO_DIR}"            # rsync source; default = local checkout

# ── Colors ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*" >&2; }

# ── Phase 1: Sync source code ──────────────────────────────────────────────
info "Syncing source from $SOURCE_DIR to $DEPLOY_PATH"
mkdir -p "$DEPLOY_PATH"
rsync -a --delete \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='.env' \
    --exclude='storage/framework' \
    --exclude='storage/logs' \
    --exclude='storage/app/media' \
    --exclude='storage/app/private' \
    --exclude='*.log' \
    "$SOURCE_DIR/" "$DEPLOY_PATH/"

cd "$DEPLOY_PATH"

# ── Phase 2: Ensure .env ───────────────────────────────────────────────────
if [[ ! -f .env ]]; then
    info "Creating .env from .env.example"
    cp .env.example .env
    php -r "echo str_repeat('?', 32);" | head -c 32 > /tmp/app_key
    sed -i "s/APP_KEY=.*/APP_KEY=$(tr -dc A-Za-z0-9 < /tmp/app_key | head -c 32)/" .env
    rm -f /tmp/app_key
    warn ".env created with random APP_KEY — review DB/REDIS/CORE settings"
fi

# ── Phase 3: Pull & build ──────────────────────────────────────────────────
info "Pulling base images"
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" pull mysql redis

info "Building application images"
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" build --pull app marketplace

# ── Phase 4: Bring up stateful services ────────────────────────────────────
info "Starting MySQL and Redis (stateful — no recreate)"
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" up -d --no-recreate mysql redis 2>/dev/null || \
    docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" up -d mysql redis

# Wait for MySQL
info "Waiting for MySQL to be healthy..."
timeout 60 bash -c 'until docker compose -p "'$COMPOSE_PROJECT'" exec -T mysql mysqladmin ping -h127.0.0.1 -uroot -p"${MYSQL_ROOT_PASSWORD:-root}" 2>/dev/null; do sleep 2; done' || {
    error "MySQL did not become healthy within 60s"
    exit 1
}

# Wait for Redis
info "Waiting for Redis..."
timeout 30 bash -c 'until docker compose -p "'$COMPOSE_PROJECT'" exec -T redis redis-cli ping 2>/dev/null | grep -q PONG; do sleep 2; done' || {
    error "Redis did not respond within 30s"
    exit 1
}

# ── Phase 5: Run migrations ────────────────────────────────────────────────
info "Running database migrations"
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" run --rm --no-deps app \
    php artisan migrate --force

# ── Phase 6: Seed (if fresh DB) ────────────────────────────────────────────
info "Checking if seeding is needed"
SEEDED=$(docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" run --rm --no-deps app \
    php artisan tinker --execute="echo \\App\\Models\\Service::count();" 2>/dev/null || echo "0")
if [[ "$SEEDED" == "0" ]]; then
    info "Empty database detected — running seeders"
    docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" run --rm --no-deps app \
        php artisan db:seed --force
else
    info "Database already seeded ($SEEDED services found) — skipping"
fi

# ── Phase 7: Bring up app containers ───────────────────────────────────────
info "Starting app + marketplace containers"
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" up -d app marketplace

# ── Phase 8: Fix storage permissions ───────────────────────────────────────
info "Fixing storage permissions"
docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" exec -u 0 app \
    chown -R www-data:www-data /var/www/html/storage

# ── Phase 9: Verify health ─────────────────────────────────────────────────
info "Verifying application health..."
sleep 5
HEALTH=$(docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" ps --format json app 2>/dev/null | grep -c "healthy" || true)
if [[ "$HEALTH" -gt 0 ]]; then
    info "✅ Application is healthy!"
else
    warn "App health check not yet passed — check logs: docker compose logs app"
fi

info "✅ Deploy complete!"
info "   Backend:  http://127.0.0.1:8000 (via host nginx on $DOMAIN)"
info "   Frontend: http://127.0.0.1:3000 (via host nginx)"
info ""
info "👉 Don't forget to:"
info "   1. Set up nginx site config from infra/nginx/aihd-lab-site.conf"
info "   2. Obtain SSL cert: certbot --nginx -d $DOMAIN"
info "   3. Edit .env with real DB/REDIS/CORE settings if needed"