#!/usr/bin/env bash
# Guard against re-coupling the services through packages/messaging (audit #15).
#
# The shared package is a path repository, so every service resolves the same
# source tree. That is fine as long as:
#   1. services do not pin an exact version ("1.0.0") — a range lets each
#      service's own composer.lock move independently;
#   2. the package's own constraints are wide enough that bumping Laravel (or
#      Ecotone) in one service does not require editing the package first.
#
# Usage (from repo root):
#   ./scripts/check-deps.sh
#
# Exit 1 on any violation. No network, no composer install needed.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit

RED='\033[0;31m'
GREEN='\033[0;32m'
BOLD='\033[1m'
NC='\033[0m'

SERVICES=(auth_service catalog_service notification_service order_service search_service recommendation_service)
FAILED=0

log() { printf '%b\n' "$*"; }

# Extract a JSON string value without jq: php -r is always available in CI lint job.
json_get() {
  local file="$1" path="$2"
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    $node = $data;
    foreach (explode(".", $argv[2]) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) { exit(1); }
        $node = $node[$key];
    }
    echo is_scalar($node) ? $node : json_encode($node);
  ' "$file" "$path"
}

log "${BOLD}Checking shop/messaging constraints${NC}"

for svc in "${SERVICES[@]}"; do
  file="$svc/composer.json"
  if [[ ! -f "$file" ]]; then
    log "  ${RED}MISSING${NC} $file"
    FAILED=1
    continue
  fi

  constraint="$(json_get "$file" "require.shop/messaging" 2>/dev/null || true)"
  if [[ -z "$constraint" ]]; then
    log "  ${RED}MISSING${NC} $svc does not require shop/messaging"
    FAILED=1
    continue
  fi

  # Exact pins ("1.0.0", "=1.0.0", "v1.0.0") couple every service to one release.
  if [[ "$constraint" =~ ^=?v?[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    log "  ${RED}EXACT PIN${NC} $svc: shop/messaging = $constraint (use a range like ^1.0)"
    FAILED=1
    continue
  fi

  log "  ${GREEN}OK${NC} $svc: shop/messaging $constraint"
done

log ""
log "${BOLD}Checking packages/messaging framework constraints${NC}"

pkg="packages/messaging/composer.json"
for dep in "illuminate/support" "illuminate/database" "illuminate/console"; do
  constraint="$(json_get "$pkg" "require.${dep}" 2>/dev/null || true)"
  if [[ -z "$constraint" ]]; then
    log "  ${RED}MISSING${NC} $pkg does not require $dep"
    FAILED=1
    continue
  fi
  # A single major (^13.0) forces every service onto that major.
  if [[ "$constraint" =~ ^\^[0-9]+\.0$ ]]; then
    log "  ${RED}TOO NARROW${NC} $dep = $constraint (allow the previous major too, e.g. ^12.0 || ^13.0)"
    FAILED=1
    continue
  fi
  log "  ${GREEN}OK${NC} $dep $constraint"
done

log ""
if [[ $FAILED -ne 0 ]]; then
  log "${RED}${BOLD}Dependency coupling check failed.${NC}"
  exit 1
fi

log "${GREEN}${BOLD}Services are decoupled from packages/messaging.${NC}"
exit 0
