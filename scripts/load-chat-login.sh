#!/usr/bin/env bash
# Regression check for ADR 0001: long recommendation chats must not starve login.
#
# Fires N concurrent /api/recommendations/chat requests (each holds a php-fpm
# worker for up to ~120s) and measures /api/login latency while they run.
# With the dedicated [gateway] pool (GATEWAY_FPM_PORT) login stays fast; with a
# single [www] pool it queues behind the chats.
#
# Usage (from repo root, stack already up):
#   ./scripts/load-chat-login.sh                 # 25 chats, 5 login probes
#   CHATS=40 PROBES=10 ./scripts/load-chat-login.sh
#   BASE_URL=http://localhost/api ./scripts/load-chat-login.sh
#
# Requires: curl. Login probes use a deliberately wrong password, so a 401/422
# is a healthy answer — we only care about latency, not the status code.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit

BASE_URL="${BASE_URL:-http://localhost/api}"
CHATS="${CHATS:-25}"
PROBES="${PROBES:-5}"
LOGIN_BUDGET_MS="${LOGIN_BUDGET_MS:-2000}"
CHAT_BODY="${CHAT_BODY:-{\"message\":\"load test\"}}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

log() { printf '%b\n' "$*"; }

if ! command -v curl >/dev/null 2>&1; then
  log "${RED}ERROR:${NC} curl not found on PATH."
  exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

log "${BOLD}Starting ${CHATS} concurrent chat requests against ${BASE_URL}/recommendations/chat${NC}"
for i in $(seq 1 "$CHATS"); do
  (
    curl -sS -o /dev/null -m 300 \
      -X POST "${BASE_URL}/recommendations/chat" \
      -H 'Content-Type: application/json' \
      -H 'Accept: application/json' \
      -d "$CHAT_BODY" >/dev/null 2>&1
  ) &
done
CHAT_PIDS="$(jobs -p)"

# Give the chats a moment to occupy their workers.
sleep 2

log "${BOLD}Probing ${BASE_URL}/login ${PROBES}x while chats are in flight${NC}"
FAILED=0
for i in $(seq 1 "$PROBES"); do
  ms="$(curl -sS -o /dev/null -m 30 \
    -w '%{time_total}' \
    -X POST "${BASE_URL}/login" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{"email":"load-test@example.com","password":"wrong-password"}' 2>/dev/null \
    | awk '{ printf "%d", $1 * 1000 }')"
  ms="${ms:-0}"
  if [[ "$ms" -le "$LOGIN_BUDGET_MS" ]]; then
    log "  ${GREEN}OK${NC}   login #${i}: ${ms}ms (budget ${LOGIN_BUDGET_MS}ms)"
  else
    log "  ${RED}SLOW${NC} login #${i}: ${ms}ms (budget ${LOGIN_BUDGET_MS}ms)"
    FAILED=1
  fi
done

log "${YELLOW}Waiting for chat requests to finish...${NC}"
# shellcheck disable=SC2086
wait $CHAT_PIDS 2>/dev/null || true

if [[ $FAILED -ne 0 ]]; then
  log ""
  log "${RED}${BOLD}Login was blocked by chat load.${NC}"
  log "Check that auth_service has GATEWAY_FPM_PORT set and that nginx includes /etc/nginx/gateway/*.conf."
  exit 1
fi

log ""
log "${GREEN}${BOLD}Login stayed within budget under chat load.${NC}"
exit 0
