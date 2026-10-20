#!/usr/bin/env bash
# Prepare .env files and PHP vendor/ for a cold local clone.
# Composer runs inside Docker (php-base image) — no host PHP required.
#
# Usage (from repo root):
#   ./scripts/bootstrap.sh
#
# Then:
#   docker compose up -d --build
#   ./scripts/test-all.sh
#   ./scripts/demo-prep.sh

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

COMPOSE=(docker compose)

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

FAILED=0

log() { printf '%b\n' "$*"; }

ensure_env() {
  local dir="$1"
  local example="$ROOT/$dir/.env.example"
  local envfile="$ROOT/$dir/.env"

  if [[ ! -f "$example" ]]; then
    log "${YELLOW}SKIP${NC} ${dir}: no .env.example"
    return 0
  fi

  if [[ ! -f "$envfile" ]]; then
    cp "$example" "$envfile"
    log "${GREEN}Created${NC} ${dir}/.env from .env.example"
  else
    log "OK ${dir}/.env already exists"
  fi
}

# Fill empty APP_KEY= (Laravel) without overwriting a set key.
ensure_app_key() {
  local dir="$1"
  local envfile="$ROOT/$dir/.env"

  [[ -f "$envfile" ]] || return 0

  if grep -qE '^APP_KEY=$' "$envfile" || grep -qE '^APP_KEY=\s*$' "$envfile"; then
    if ! command -v openssl >/dev/null 2>&1; then
      log "${RED}ERROR:${NC} openssl not found; cannot generate APP_KEY for ${dir}"
      FAILED=1
      return 1
    fi
    local key
    key="base64:$(openssl rand -base64 32)"
    # Escape for sed replacement
    local escaped
    escaped=$(printf '%s' "$key" | sed -e 's/[\/&]/\\&/g')
    sed -i "s/^APP_KEY=$/APP_KEY=${escaped}/" "$envfile"
    log "${GREEN}Set${NC} ${dir}/.env APP_KEY"
  fi
}

# True if vendor/autoload.php is visible inside the app container (bind-mount).
# Prefer this over a host-path check: with `act` without --bind, Docker mounts
# the host tree while the job sees a copy without gitignored vendor/.
vendor_visible_in_container() {
  local app="$1"
  "${COMPOSE[@]}" run --rm --no-deps "$app" test -f vendor/autoload.php
}

ensure_vendor() {
  local dir="$1"
  local app="$2"

  if vendor_visible_in_container "$app"; then
    log "OK ${dir}/vendor already present"
    return 0
  fi

  log ""
  log "${BOLD}═══ composer install → ${dir} (via ${app}) ═══${NC}"
  if "${COMPOSE[@]}" run --rm --no-deps "$app" \
    composer install --no-interaction --prefer-dist; then
    if vendor_visible_in_container "$app"; then
      log "${GREEN}PASS${NC} ${dir}/vendor"
      return 0
    fi
    log "${RED}FAIL${NC} ${dir}: composer finished but vendor/autoload.php missing in container"
    log "  Hint: if using act, re-run with ${BOLD}--bind${NC} so Docker mounts match the job workspace."
    FAILED=1
    return 1
  fi
  log "${RED}FAIL${NC} ${dir}: composer install failed"
  FAILED=1
  return 1
}

# --- .env ---
log "${BOLD}Ensuring .env files...${NC}"
ENV_DIRS=(
  auth_service
  catalog_service
  notification_service
  order_service
  search_service
  analytic_service
  recommendation_service
  frontend
)
for d in "${ENV_DIRS[@]}"; do
  ensure_env "$d"
done

PHP_ENV_DIRS=(
  auth_service
  catalog_service
  notification_service
  order_service
  search_service
  recommendation_service
)
for d in "${PHP_ENV_DIRS[@]}"; do
  ensure_app_key "$d" || true
done

# --- images + vendor ---
log ""
log "${BOLD}Building php-base (composer lives here)...${NC}"
if ! "${COMPOSE[@]}" build php-base; then
  log "${RED}ERROR:${NC} docker compose build php-base failed"
  exit 1
fi

log ""
log "${BOLD}Building PHP app images (needed for compose run)...${NC}"
if ! "${COMPOSE[@]}" build \
  auth_app catalog_app notification_app order_app search_app recommendation_app; then
  log "${RED}ERROR:${NC} docker compose build of PHP apps failed"
  exit 1
fi

log ""
log "${BOLD}Ensuring vendor/ via Docker composer...${NC}"
ensure_vendor auth_service auth_app
ensure_vendor catalog_service catalog_app
ensure_vendor notification_service notification_app
ensure_vendor order_service order_app
ensure_vendor search_service search_app
ensure_vendor recommendation_service recommendation_app

log ""
if [[ $FAILED -ne 0 ]]; then
  log "${RED}${BOLD}Bootstrap finished with errors.${NC}"
  exit 1
fi

log "${GREEN}${BOLD}Bootstrap complete.${NC}"
log "Next: docker compose up -d --build"
exit 0
