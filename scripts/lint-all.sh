#!/usr/bin/env bash
# Lint microservices locally:
#   Laravel — Pint, PHPStan/Larastan, Rector --dry-run (each service's vendor/bin)
#   analytic — golangci-lint (host Go + golangci-lint v2.15.x)
# No Docker. PHPStan, Larastan, Pint and Rector live in each service.
#
# Usage (from repo root):
#   ./scripts/lint-all.sh                 # check, then offer autofix if style fails
#   ./scripts/lint-all.sh --fix           # check, then autofix without asking
#   ./scripts/lint-all.sh --no-fix        # check only (CI / non-interactive)
#   ./scripts/lint-all.sh auth catalog    # filter by short name
#   ./scripts/lint-all.sh analytic        # Go only (no PHP required)
#
# Short names: auth catalog notification order recommendation search analytic

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

FAILED=0
PINT_FAILED=0
PHPSTAN_FAILED=0
RECTOR_FAILED=0
GOLANGCI_FAILED=0
DO_FIX=ask
declare -a RESULTS=()
declare -a FILTER=()
declare -a PINT_PATHS=()
declare -a SELECTED_PHP=()
RUN_ANALYTIC=0

log() { printf '%b\n' "$*"; }

# --- arg parse ---
for arg in "$@"; do
  case "$arg" in
    --fix)
      DO_FIX=yes
      ;;
    --no-fix)
      DO_FIX=no
      ;;
    -h|--help)
      sed -n '2,14p' "$0" | sed 's/^# \?//'
      exit 0
      ;;
    -*)
      log "${RED}ERROR:${NC} unknown flag '${arg}'. Use --fix, --no-fix, or service names."
      exit 2
      ;;
    *)
      FILTER+=("$arg")
      ;;
  esac
done

PHP_SERVICES=(auth catalog notification order recommendation search)
VALID=(auth catalog notification order recommendation search analytic)
declare -A DIR_OF=(
  [auth]=auth_service
  [catalog]=catalog_service
  [notification]=notification_service
  [order]=order_service
  [recommendation]=recommendation_service
  [search]=search_service
  [analytic]=analytic_service
)

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

for name in "${PHP_SERVICES[@]}"; do
  if should_run "$name"; then
    SELECTED_PHP+=("$name")
    PINT_PATHS+=("${DIR_OF[$name]}")
  fi
done

if should_run analytic; then
  RUN_ANALYTIC=1
fi

require_host_php() {
  if ! command -v php >/dev/null 2>&1; then
    log "${RED}ERROR:${NC} php not found on PATH (local lint only)."
    exit 1
  fi
  if ! command -v composer >/dev/null 2>&1; then
    log "${RED}ERROR:${NC} composer not found on PATH (local lint only)."
    exit 1
  fi
}

# golangci-lint must be built with Go >= the module version (1.27.0).
# The v1.64 binary on PATH is built with go1.24 and cannot load this module.
GOLANGCI_VERSION=2.15.0
GOLANGCI_BIN=""

golangci_built_go() {
  "$1" version 2>/dev/null | sed -n 's/.*built with go\([0-9][0-9.]*\).*/\1/p' | head -1
}

golangci_supports_module() {
  local built
  built="$(golangci_built_go "$1")"
  [[ -n "$built" ]] && [[ "$(printf '%s\n%s\n' "1.27.0" "$built" | sort -V | head -1)" == "1.27.0" ]]
}

ensure_golangci() {
  local candidate="$ROOT/.tools/golangci-lint"
  if command -v golangci-lint >/dev/null 2>&1 && golangci_supports_module "$(command -v golangci-lint)"; then
    GOLANGCI_BIN="$(command -v golangci-lint)"
    return 0
  fi
  if [[ -x "$candidate" ]] && golangci_supports_module "$candidate"; then
    GOLANGCI_BIN="$candidate"
    return 0
  fi
  log "${YELLOW}golangci-lint on PATH cannot load go 1.27.0 — downloading v${GOLANGCI_VERSION}${NC}"
  mkdir -p "$ROOT/.tools"
  local url="https://github.com/golangci/golangci-lint/releases/download/v${GOLANGCI_VERSION}/golangci-lint-${GOLANGCI_VERSION}-linux-amd64.tar.gz"
  local tmp
  tmp="$(mktemp)"
  if ! curl -fsSL -o "$tmp" "$url"; then
    log "${RED}ERROR:${NC} failed to download golangci-lint v${GOLANGCI_VERSION}"
    rm -f "$tmp"
    exit 1
  fi
  tar -C "$ROOT/.tools" --strip-components=1 -xzf "$tmp" "golangci-lint-${GOLANGCI_VERSION}-linux-amd64/golangci-lint"
  rm -f "$tmp"
  chmod +x "$candidate"
  if ! golangci_supports_module "$candidate"; then
    log "${RED}ERROR:${NC} downloaded golangci-lint still cannot load go 1.27.0"
    exit 1
  fi
  GOLANGCI_BIN="$candidate"
}

require_host_go() {
  if ! command -v go >/dev/null 2>&1; then
    log "${RED}ERROR:${NC} go not found on PATH (needed for analytic lint)."
    exit 1
  fi
  ensure_golangci
}

# Larastan bootstraps each Laravel app from that service's own vendor/.
ensure_service_vendor() {
  local dir="$1"
  if [[ -f "$ROOT/$dir/vendor/autoload.php" \
    && -x "$ROOT/$dir/vendor/bin/pint" \
    && -x "$ROOT/$dir/vendor/bin/phpstan" \
    && -x "$ROOT/$dir/vendor/bin/rector" ]]; then
    return 0
  fi

  log "${YELLOW}Missing${NC} ${dir}/vendor — running composer install..."
  # Host/CI PHP often lacks rdkafka/mongodb (they live in php-base Docker).
  # Pint/PHPStan only need the packages on disk, not the native extensions.
  if ! (cd "$ROOT/$dir" && composer install --no-interaction --prefer-dist \
    --ignore-platform-req=ext-rdkafka \
    --ignore-platform-req=ext-mongodb); then
    log "${RED}ERROR:${NC} composer install failed for ${dir}"
    return 1
  fi
  if [[ ! -f "$ROOT/$dir/vendor/autoload.php" ]]; then
    log "${RED}ERROR:${NC} composer finished but ${dir}/vendor/autoload.php is still missing"
    return 1
  fi
  log "${GREEN}Installed${NC} ${dir}/vendor"
  return 0
}

# Non-interactive stdin → never prompt for fix
if [[ "$DO_FIX" == "ask" ]] && [[ ! -t 0 ]]; then
  DO_FIX=no
fi

if [[ ${#SELECTED_PHP[@]} -eq 0 && $RUN_ANALYTIC -eq 0 ]]; then
  log "${RED}ERROR:${NC} no services selected."
  exit 2
fi

if [[ ${#SELECTED_PHP[@]} -gt 0 ]]; then
  require_host_php
  for name in "${SELECTED_PHP[@]}"; do
    ensure_service_vendor "${DIR_OF[$name]}" || exit 1
  done
fi

if [[ $RUN_ANALYTIC -eq 1 ]]; then
  require_host_go
fi

TOTAL_START=$(date +%s)

# --- Pint (check) ---
if [[ ${#PINT_PATHS[@]} -gt 0 ]]; then
  log ""
  log "${BOLD}═══ [pint] --test ${PINT_PATHS[*]} ═══${NC}"
  PINT_START=$(date +%s)
  PINT_RC=0
  for name in "${SELECTED_PHP[@]}"; do
    dir="${DIR_OF[$name]}"
    if ! (cd "$ROOT/$dir" && ./vendor/bin/pint --config="$ROOT/pint.json" --test); then
      PINT_RC=1
    fi
  done
  PINT_ELAPSED=$(( $(date +%s) - PINT_START ))
  if [[ $PINT_RC -eq 0 ]]; then
    log "${GREEN}PASS${NC} [pint] (${PINT_ELAPSED}s)"
    RESULTS+=("PASS pint")
  else
    log "${RED}FAIL${NC} [pint] exit=${PINT_RC} (${PINT_ELAPSED}s)"
    RESULTS+=("FAIL pint")
    PINT_FAILED=1
    FAILED=1
  fi
fi

# --- PHPStan (per service — Larastan needs app bootstrap) ---
for name in "${SELECTED_PHP[@]+"${SELECTED_PHP[@]}"}"; do
  dir="${DIR_OF[$name]}"
  log ""
  log "${BOLD}═══ [phpstan/${name}] analyse ═══${NC}"
  PHPSTAN_START=$(date +%s)
  if (
    cd "$ROOT/$dir" &&
      ./vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G
  ); then
    PHPSTAN_RC=0
  else
    PHPSTAN_RC=$?
  fi
  PHPSTAN_ELAPSED=$(( $(date +%s) - PHPSTAN_START ))
  if [[ $PHPSTAN_RC -eq 0 ]]; then
    log "${GREEN}PASS${NC} [phpstan/${name}] (${PHPSTAN_ELAPSED}s)"
    RESULTS+=("PASS phpstan/${name}")
  else
    log "${RED}FAIL${NC} [phpstan/${name}] exit=${PHPSTAN_RC} (${PHPSTAN_ELAPSED}s)"
    RESULTS+=("FAIL phpstan/${name}")
    PHPSTAN_FAILED=1
    FAILED=1
  fi
done

# --- Rector (dry-run: Laravel sets, prepared sets, strict_types, final) ---
for name in "${SELECTED_PHP[@]+"${SELECTED_PHP[@]}"}"; do
  dir="${DIR_OF[$name]}"
  log ""
  log "${BOLD}═══ [rector/${name}] --dry-run ═══${NC}"
  RECTOR_START=$(date +%s)
  if (
    cd "$ROOT/$dir" &&
      ./vendor/bin/rector process --config="$ROOT/rector.php" --dry-run --no-progress-bar
  ); then
    RECTOR_RC=0
  else
    RECTOR_RC=$?
  fi
  RECTOR_ELAPSED=$(( $(date +%s) - RECTOR_START ))
  if [[ $RECTOR_RC -eq 0 ]]; then
    log "${GREEN}PASS${NC} [rector/${name}] (${RECTOR_ELAPSED}s)"
    RESULTS+=("PASS rector/${name}")
  else
    log "${RED}FAIL${NC} [rector/${name}] exit=${RECTOR_RC} (${RECTOR_ELAPSED}s)"
    RESULTS+=("FAIL rector/${name}")
    RECTOR_FAILED=1
    FAILED=1
  fi
done

# Pin toolchain to module's go version so host Go (e.g. 1.26) does not break
# golangci-lint typecheck against a newer stdlib.
GOLANGCI_GOTOOLCHAIN="${GOLANGCI_GOTOOLCHAIN:-go1.27.0}"

run_golangci() {
  local -a extra=("$@")
  (
    cd "$ROOT/analytic_service" &&
      GOTOOLCHAIN="$GOLANGCI_GOTOOLCHAIN" "$GOLANGCI_BIN" run "${extra[@]}" ./...
  )
}

# --- golangci-lint (analytic) ---
if [[ $RUN_ANALYTIC -eq 1 ]]; then
  log ""
  log "${BOLD}═══ [golangci/analytic] run ./... ═══${NC}"
  GOLANGCI_START=$(date +%s)
  if run_golangci; then
    GOLANGCI_RC=0
  else
    GOLANGCI_RC=$?
  fi
  GOLANGCI_ELAPSED=$(( $(date +%s) - GOLANGCI_START ))
  if [[ $GOLANGCI_RC -eq 0 ]]; then
    log "${GREEN}PASS${NC} [golangci/analytic] (${GOLANGCI_ELAPSED}s)"
    RESULTS+=("PASS golangci/analytic")
  else
    log "${RED}FAIL${NC} [golangci/analytic] exit=${GOLANGCI_RC} (${GOLANGCI_ELAPSED}s)"
    RESULTS+=("FAIL golangci/analytic")
    GOLANGCI_FAILED=1
    FAILED=1
  fi
fi

TOTAL_ELAPSED=$(( $(date +%s) - TOTAL_START ))

log ""
log "${BOLD}═══ Summary (${TOTAL_ELAPSED}s) ═══${NC}"
for r in "${RESULTS[@]+"${RESULTS[@]}"}"; do
  if [[ "$r" == PASS* ]]; then
    log "  ${GREEN}${r}${NC}"
  else
    log "  ${RED}${r}${NC}"
  fi
done

recompute_failed() {
  FAILED=0
  if [[ $PINT_FAILED -ne 0 || $PHPSTAN_FAILED -ne 0 || $RECTOR_FAILED -ne 0 || $GOLANGCI_FAILED -ne 0 ]]; then
    FAILED=1
  fi
}

# --- Autofix offer (Pint) ---
if [[ $PINT_FAILED -ne 0 ]]; then
  APPLY=0
  if [[ "$DO_FIX" == "yes" ]]; then
    APPLY=1
  elif [[ "$DO_FIX" == "ask" ]]; then
    log ""
    printf '%b' "${YELLOW}Применить автофикс Pint? [y/N] ${NC}"
    read -r answer || answer=
    case "$answer" in
      y|Y|yes|YES) APPLY=1 ;;
      *) APPLY=0 ;;
    esac
  fi

  if [[ $APPLY -eq 1 ]]; then
    log ""
    log "${BOLD}═══ [pint] autofix ${PINT_PATHS[*]} ═══${NC}"
    pint_fix_ok=0
    for name in "${SELECTED_PHP[@]}"; do
      dir="${DIR_OF[$name]}"
      if ! (cd "$ROOT/$dir" && ./vendor/bin/pint --config="$ROOT/pint.json"); then
        pint_fix_ok=1
      fi
    done
    if [[ $pint_fix_ok -eq 0 ]]; then
      log "${GREEN}Pint autofix applied.${NC} Re-run ./scripts/lint-all.sh to verify."
      PINT_FAILED=0
      recompute_failed
    else
      log "${RED}Pint autofix failed.${NC}"
    fi
  else
    log ""
    log "Pint autofix skipped. Run ${BOLD}./scripts/lint-all.sh --fix${NC} later to apply."
  fi
fi

# --- Autofix offer (golangci-lint) ---
if [[ $GOLANGCI_FAILED -ne 0 ]]; then
  APPLY=0
  if [[ "$DO_FIX" == "yes" ]]; then
    APPLY=1
  elif [[ "$DO_FIX" == "ask" ]]; then
    log ""
    printf '%b' "${YELLOW}Применить автофикс golangci-lint (--fix)? [y/N] ${NC}"
    read -r answer || answer=
    case "$answer" in
      y|Y|yes|YES) APPLY=1 ;;
      *) APPLY=0 ;;
    esac
  fi

  if [[ $APPLY -eq 1 ]]; then
    log ""
    log "${BOLD}═══ [golangci/analytic] autofix ./... ═══${NC}"
    if run_golangci --fix; then
      log "${GREEN}golangci-lint autofix applied.${NC} Re-run ./scripts/lint-all.sh analytic to verify."
      GOLANGCI_FAILED=0
      recompute_failed
    else
      # --fix may still exit non-zero if non-fixable issues remain
      log "${YELLOW}golangci-lint --fix finished with remaining issues (not all are auto-fixable).${NC}"
      GOLANGCI_FAILED=1
      recompute_failed
    fi
  else
    log ""
    log "golangci autofix skipped. Run ${BOLD}./scripts/lint-all.sh --fix analytic${NC} later to apply."
  fi
fi

if [[ $PHPSTAN_FAILED -ne 0 ]]; then
  log ""
  log "${YELLOW}PHPStan errors are not auto-fixed — edit the reported code manually.${NC}"
fi

if [[ $GOLANGCI_FAILED -ne 0 ]]; then
  log ""
  log "${YELLOW}Remaining golangci issues are not auto-fixed — edit the reported code manually.${NC}"
fi

if [[ $FAILED -ne 0 ]]; then
  log ""
  log "${RED}${BOLD}Lint finished with errors.${NC}"
  exit 1
fi

log ""
log "${GREEN}${BOLD}All selected lint checks passed.${NC}"
exit 0
