#!/usr/bin/env bash
# Apply pending migrations and seeders for a demo-ready local stack.
#
# Usage (from repo root):
#   ./scripts/demo-prep.sh                 # all services
#   ./scripts/demo-prep.sh auth catalog    # filter by short name
#
# Does NOT start containers. Bring the stack up first, e.g.:
#   docker compose up -d
#
# Order matters: search indices before catalog seed (Kafka republish),
# recommendation schema + product consumer before catalog seed (AI index),
# catalog before order seed (ProductSnapshotSeeder reads catalog DB).
#
# Analytic ClickHouse migrations run automatically when analytic_api /
# analytic_consumers start — no artisan step here.
#
# Short names: auth notification search catalog order recommendation

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit

COMPOSE=(docker compose)

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

FAILED=0
declare -a RESULTS=()

# Gemini embed of ~45 seeded products can take several minutes.
RECO_INDEX_TIMEOUT_SEC="${RECO_INDEX_TIMEOUT_SEC:-600}"
RECO_INDEX_POLL_SEC="${RECO_INDEX_POLL_SEC:-5}"

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

run_step() {
  local label="$1"
  local app="$2"
  shift 2
  local start end elapsed rc
  log ""
  log "${BOLD}═══ [${label}] $* ═══${NC}"
  start=$(date +%s)
  if "${COMPOSE[@]}" exec -T "$app" "$@"; then
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

catalog_product_count() {
  "${COMPOSE[@]}" exec -T catalog_app php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (int) App\Models\Product::query()->count();
'
}

recommendation_indexed_count() {
  "${COMPOSE[@]}" exec -T recommendation_app php -r '
$url = "http://127.0.0.1:8007/api/recommendations/health";
$raw = @file_get_contents($url);
if ($raw === false) {
    fwrite(STDERR, "health unreachable\n");
    exit(1);
}
$json = json_decode($raw, true);
if (!is_array($json)) {
    fwrite(STDERR, "invalid health json\n");
    exit(1);
}
echo (int) ($json["indexed_products"] ?? 0);
'
}

wait_for_recommendation_index() {
  local label="recommendation-index-wait"
  local expected indexed
  local start end elapsed
  local deadline

  log ""
  log "${BOLD}═══ [${label}] wait for product_catalog embeddings ═══${NC}"
  start=$(date +%s)

  if ! expected="$(catalog_product_count 2>/dev/null)"; then
    log "${RED}FAIL${NC} [${label}] cannot read catalog product count"
    RESULTS+=("FAIL ${label}")
    FAILED=1
    return 1
  fi
  expected="$(echo "$expected" | tr -d '[:space:]')"
  if [[ -z "$expected" || "$expected" -lt 1 ]]; then
    log "${YELLOW}WARN${NC} [${label}] catalog has 0 products — skip wait"
    RESULTS+=("PASS ${label} (nothing to index)")
    return 0
  fi

  log "Expecting indexed_products >= ${expected} (timeout ${RECO_INDEX_TIMEOUT_SEC}s)"
  deadline=$((start + RECO_INDEX_TIMEOUT_SEC))

  while true; do
    if indexed="$(recommendation_indexed_count 2>/dev/null)"; then
      indexed="$(echo "$indexed" | tr -d '[:space:]')"
      log "  indexed_products=${indexed}/${expected}"
      if [[ -n "$indexed" && "$indexed" -ge "$expected" ]]; then
        end=$(date +%s)
        elapsed=$((end - start))
        log "${GREEN}PASS${NC} [${label}] (${elapsed}s)"
        RESULTS+=("PASS ${label}")
        return 0
      fi
    else
      log "  health not ready yet..."
    fi

    if [[ $(date +%s) -ge $deadline ]]; then
      end=$(date +%s)
      elapsed=$((end - start))
      log "${RED}FAIL${NC} [${label}] timeout after ${elapsed}s (indexed=${indexed:-?}/${expected})"
      log "  Check GEMINI_API_KEY in recommendation .env and logs of recommendation_kafka_product_consumer."
      RESULTS+=("FAIL ${label}")
      FAILED=1
      return 1
    fi
    sleep "$RECO_INDEX_POLL_SEC"
  done
}

FILTER=("$@")

VALID=(auth notification search catalog order recommendation)
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

declare -a NEED=()
add_need() {
  local s
  for s in "$@"; do
    NEED+=("$s")
  done
}

if should_run auth; then
  add_need auth_app
fi
if should_run notification; then
  add_need notification_app
fi
if should_run search; then
  add_need search_app search_opensearch
fi
if should_run catalog; then
  add_need catalog_app kafka catalog_kafka_order_consumer catalog_kafka_outbox_worker \
    search_kafka_product_consumer search_kafka_category_consumer search_kafka_stock_consumer \
    recommendation_app recommendation_db recommendation_kafka_product_consumer
fi
if should_run order; then
  add_need order_app catalog_db kafka \
    order_kafka_outbox_worker order_kafka_stock_consumer \
    catalog_kafka_order_consumer catalog_kafka_outbox_worker
fi
if should_run recommendation; then
  add_need recommendation_app recommendation_db recommendation_kafka_product_consumer
  # recommendation-only path republishes from catalog via Kafka
  if ! should_run catalog; then
    add_need catalog_app kafka
  fi
fi

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

TOTAL_START=$(date +%s)

# Fixed order for cross-service dependencies (filter still skips unselected).
if should_run auth; then
  run_step auth auth_app php artisan migrate --seed --force
fi
if should_run notification; then
  run_step notification notification_app php artisan migrate --seed --force
fi
if should_run search; then
  # Wipe stale docs from prior seeds (truncate does not emit product.deleted).
  run_step search search_app php artisan opensearch:setup --recreate --force
fi

# Recommendation schema must exist before catalog Kafka republish is consumed.
if should_run catalog || should_run recommendation; then
  run_step recommendation-migrate recommendation_app php artisan migrate --force
fi

# Drop stale embeddings so wait_for_recommendation_index cannot PASS on old rows.
if should_run catalog || should_run recommendation; then
  run_step recommendation-clear-index recommendation_app php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::table("product_catalog")->truncate();
echo "product_catalog truncated\n";
'
fi

if should_run catalog; then
  run_step catalog catalog_app php artisan migrate --seed --force
  if [[ $FAILED -eq 0 ]]; then
    wait_for_recommendation_index
  fi
fi

if should_run order; then
  run_step order order_app php artisan migrate --seed --force
fi

# recommendation-only: catalog already seeded — republish so AI index fills via Kafka.
if should_run recommendation && ! should_run catalog; then
  run_step recommendation-republish catalog_app php artisan db:seed --class=Database\\Seeders\\RepublishProductsSeeder --force
  if [[ $FAILED -eq 0 ]]; then
    wait_for_recommendation_index
  fi
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

log ""
log "${YELLOW}Note:${NC} analytic ClickHouse schema migrates on analytic_api / analytic_consumers startup."
log "${YELLOW}Note:${NC} recommendation product_catalog is filled via Kafka after catalog seed (needs GEMINI_API_KEY)."

if [[ $FAILED -ne 0 ]]; then
  log ""
  log "${RED}${BOLD}Some steps failed.${NC}"
  exit 1
fi

log ""
log "${GREEN}${BOLD}Demo stack is ready.${NC}"
exit 0
