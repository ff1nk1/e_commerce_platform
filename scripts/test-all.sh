#!/usr/bin/env bash
# Run unit + feature + integration tests for all microservices inside Docker.
#
# Usage (from repo root):
#   ./scripts/test-all.sh              # all services
#   ./scripts/test-all.sh auth order   # filter by short name
#
# Does NOT start containers. Bring up what you need first, e.g.:
#   docker compose up -d \
#     auth_app auth_redis \
#     catalog_app catalog_redis \
#     notification_app notification_mongodb notification_mailhog \
#     order_app order_db order_redis \
#     search_app search_opensearch \
#     analytic_clickhouse kafka
#
# Prerequisites:
#   - PHP vendor/: auto-installed via Docker composer if missing (or ./scripts/bootstrap.sh)
#   - order integration: STRIPE_SECRET + STRIPE_WEBHOOK_SECRET in order_service/.env
#     (real Stripe test-mode API)
#   - analytic: pulls golang:1.23-bookworm if missing; joins network php_project_net
#
# Short names: auth catalog notification order search analytic recommendation

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

COMPOSE=(docker compose)
NETWORK=php_project_net
GOLANG_IMAGE=golang:1.23-bookworm

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

FAILED=0
declare -a RESULTS=()

log() { printf '%b\n' "$*"; }

require_running() {
  local svc="$1"
  if [[ -z "$("${COMPOSE[@]}" ps --status running -q "$svc" 2>/dev/null)" ]]; then
    log "${RED}ERROR:${NC} compose service '${BOLD}${svc}${NC}' is not running."
    log "  Start it with: docker compose up -d ${svc}"
    return 1
  fi
  return 0
}

# True if vendor/autoload.php is visible inside the app container (bind-mount).
# Prefer this over a host-path check: with `act` without --bind, Docker mounts
# the host tree while the job sees a copy without gitignored vendor/.
vendor_visible_in_container() {
  local app="$1"
  "${COMPOSE[@]}" run --rm --no-deps "$app" test -f vendor/autoload.php
}

# Install vendor/ via bind-mount if missing (no host PHP required).
ensure_vendor() {
  local dir="$1"
  local app="$2"

  if vendor_visible_in_container "$app"; then
    return 0
  fi

  log "${YELLOW}Missing${NC} ${dir}/vendor — running composer install via ${app}..."
  if ! "${COMPOSE[@]}" run --rm --no-deps "$app" \
    composer install --no-interaction --prefer-dist; then
    log "${RED}ERROR:${NC} composer install failed for ${dir}"
    return 1
  fi
  if ! vendor_visible_in_container "$app"; then
    log "${RED}ERROR:${NC} composer finished but ${dir}/vendor/autoload.php is still missing in container"
    log "  Hint: if using act, re-run with ${BOLD}--bind${NC} so Docker mounts match the job workspace."
    return 1
  fi
  log "${GREEN}Installed${NC} ${dir}/vendor"
  return 0
}

should_run() {
  local name="$1"
  if [[ ${#FILTER[@]} -eq 0 ]]; then
    return 0
  fi
  local f
  for f in "${FILTER[@]}"; do
    if [[ "$f" == "$name" ]]; then
      return 0
    fi
  done
  return 1
}

# Read KEY from a dotenv file (first match). Strips surrounding single/double quotes.
dotenv_get() {
  local file="$1"
  local key="$2"
  local line value
  [[ -f "$file" ]] || return 0
  line="$(grep -E "^${key}=" "$file" | head -n1 || true)"
  [[ -n "$line" ]] || return 0
  value="${line#*=}"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  if [[ "$value" == \"*\" ]]; then
    value="${value:1:-1}"
  elif [[ "$value" == \'*\' ]]; then
    value="${value:1:-1}"
  fi
  printf '%s' "$value"
}

# Real Stripe test-mode keys only — reject empty / phpunit dummy placeholders.
stripe_env_usable() {
  local value="$1"
  [[ -n "$value" && "$value" != *dummy* ]]
}

# Ensure order_service/.env has usable Stripe secrets; print clear error if not.
require_stripe_env() {
  local envfile="$ROOT/order_service/.env"
  STRIPE_SECRET="$(dotenv_get "$envfile" STRIPE_SECRET)"
  STRIPE_WEBHOOK_SECRET="$(dotenv_get "$envfile" STRIPE_WEBHOOK_SECRET)"
  if ! stripe_env_usable "$STRIPE_SECRET" || ! stripe_env_usable "$STRIPE_WEBHOOK_SECRET"; then
    log "${RED}ERROR:${NC} order integration needs real Stripe test-mode keys in ${BOLD}order_service/.env${NC}:"
    log "  STRIPE_SECRET + STRIPE_WEBHOOK_SECRET (non-empty, not *dummy*)"
    log "  CI: set repository secrets STRIPE_SECRET and STRIPE_WEBHOOK_SECRET (see deploy.yml inject step)."
    return 1
  fi
  return 0
}

run_php_suite() {
  local label="$1"
  local app="$2"
  shift 2
  local -a exec_env=()
  # Optional leading -e KEY=VALUE pairs (passed to docker compose exec).
  while [[ $# -gt 0 && "$1" == -e ]]; do
    exec_env+=(-e "$2")
    shift 2
  done
  local start end elapsed rc
  log ""
  log "${BOLD}═══ [${label}] $* ═══${NC}"
  start=$(date +%s)
  if "${COMPOSE[@]}" exec -T "${exec_env[@]}" "$app" "$@"; then
    rc=0
  else
    rc=$?
  fi
  end=$(date +%s)
  elapsed=$((end - start))
  if [[ $rc -eq 0 ]]; then
    log "${GREEN}PASS${NC} [${label}] (${elapsed}s)"
    RESULTS+=("PASS ${label}")
  else
    log "${RED}FAIL${NC} [${label}] exit=${rc} (${elapsed}s)"
    RESULTS+=("FAIL ${label}")
    FAILED=1
  fi
}

run_analytic() {
  local start end elapsed rc
  log ""
  log "${BOLD}═══ [analytic] go test ./... + -tags=integration ═══${NC}"
  start=$(date +%s)
  if docker run --rm --network "$NETWORK" \
    -v "$ROOT/analytic_service:/src" -w /src \
    -e CLICKHOUSE_ADDR=analytic_clickhouse:9000 \
    -e CLICKHOUSE_DATABASE=analytics_test \
    -e CLICKHOUSE_USER=default \
    -e CLICKHOUSE_PASSWORD=analytic_local_secret \
    -e KAFKA_BROKERS=kafka:29092 \
    -e MIGRATIONS_DIR=/src/migrations \
    "$GOLANG_IMAGE" \
    bash -c 'go test ./... && go test -tags=integration ./internal/kafka/...'; then
    rc=0
  else
    rc=$?
  fi
  end=$(date +%s)
  elapsed=$((end - start))
  if [[ $rc -eq 0 ]]; then
    log "${GREEN}PASS${NC} [analytic] (${elapsed}s)"
    RESULTS+=("PASS analytic")
  else
    log "${RED}FAIL${NC} [analytic] exit=${rc} (${elapsed}s)"
    RESULTS+=("FAIL analytic")
    FAILED=1
  fi
}

FILTER=("$@")

# Validate filter names early
VALID=(auth catalog notification order search analytic recommendation)
for f in "${FILTER[@]+"${FILTER[@]}"}"; do
  ok=0
  for v in "${VALID[@]}"; do
    if [[ "$f" == "$v" ]]; then ok=1; break; fi
  done
  if [[ $ok -eq 0 ]]; then
    log "${RED}ERROR:${NC} unknown service '${f}'. Valid: ${VALID[*]}"
    exit 2
  fi
done

# Collect required compose services for the selected filter
declare -a NEED=()
add_need() {
  local s
  for s in "$@"; do
    NEED+=("$s")
  done
}

if should_run auth; then
  add_need auth_app auth_redis
fi
if should_run catalog; then
  add_need catalog_app catalog_redis
fi
if should_run notification; then
  add_need notification_app notification_mongodb notification_mailhog
fi
if should_run order; then
  add_need order_app order_db order_redis
fi
if should_run search; then
  add_need search_app search_opensearch
fi
if should_run analytic; then
  add_need analytic_clickhouse kafka
fi
if should_run recommendation; then
  add_need recommendation_app recommendation_db
fi

# Dedupe NEED
declare -A SEEN=()
declare -a UNIQUE=()
for s in "${NEED[@]}"; do
  if [[ -z "${SEEN[$s]+x}" ]]; then
    SEEN[$s]=1
    UNIQUE+=("$s")
  fi
done

log "${BOLD}Checking running containers...${NC}"
CHECK_FAIL=0
for s in "${UNIQUE[@]}"; do
  if ! require_running "$s"; then
    CHECK_FAIL=1
  fi
done
if [[ $CHECK_FAIL -ne 0 ]]; then
  log ""
  log "${YELLOW}Hint:${NC} from repo root, start missing services then re-run:"
  log "  docker compose up -d ${UNIQUE[*]}"
  exit 1
fi
log "${GREEN}All required containers are running.${NC}"

# PHP vendor (auto-install via Docker if missing)
if should_run auth; then ensure_vendor auth_service auth_app || exit 1; fi
if should_run catalog; then ensure_vendor catalog_service catalog_app || exit 1; fi
if should_run notification; then ensure_vendor notification_service notification_app || exit 1; fi
if should_run order; then ensure_vendor order_service order_app || exit 1; fi
if should_run search; then ensure_vendor search_service search_app || exit 1; fi
if should_run recommendation; then ensure_vendor recommendation_service recommendation_app || exit 1; fi

TOTAL_START=$(date +%s)

if should_run auth; then
  run_php_suite auth auth_app php artisan test
fi
if should_run catalog; then
  run_php_suite catalog catalog_app php artisan test
fi
if should_run notification; then
  run_php_suite notification notification_app php artisan test
fi
if should_run order; then
  run_php_suite order order_app php artisan test
  # Inject Stripe secrets at exec time so stale empty container $_SERVER cannot
  # block Laravel (same class of bug as JWT; keys must come from .env / CI).
  if require_stripe_env; then
    run_php_suite "order/integration" order_app \
      -e "STRIPE_SECRET=${STRIPE_SECRET}" \
      -e "STRIPE_WEBHOOK_SECRET=${STRIPE_WEBHOOK_SECRET}" \
      php artisan test -c phpunit.integration.xml
  else
    RESULTS+=("FAIL order/integration")
    FAILED=1
  fi
fi
if should_run search; then
  run_php_suite search search_app php artisan test
  run_php_suite "search/integration" search_app php artisan test -c phpunit.integration.xml
fi
if should_run analytic; then
  run_analytic
fi
if should_run recommendation; then
  run_php_suite recommendation recommendation_app php artisan test
fi

TOTAL_END=$(date +%s)
TOTAL_ELAPSED=$((TOTAL_END - TOTAL_START))

log ""
log "${BOLD}═══ Summary (${TOTAL_ELAPSED}s) ═══${NC}"
for r in "${RESULTS[@]+"${RESULTS[@]}"}"; do
  if [[ "$r" == PASS* ]]; then
    log "  ${GREEN}${r}${NC}"
  else
    log "  ${RED}${r}${NC}"
  fi
done

if [[ $FAILED -ne 0 ]]; then
  log ""
  log "${RED}${BOLD}Some suites failed.${NC}"
  exit 1
fi

log ""
log "${GREEN}${BOLD}All selected suites passed.${NC}"
exit 0
