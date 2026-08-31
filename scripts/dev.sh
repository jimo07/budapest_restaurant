#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HERD_PHP="/Users/jimo/Library/Application Support/Herd/bin/php"
PHP_BIN="$(command -v php || true)"
[[ -n "$PHP_BIN" ]] || PHP_BIN="$HERD_PHP"
SERVER_PORT="${SERVER_PORT:-8000}"
CUSTOMER_PORT="${CUSTOMER_PORT:-5173}"
ADMIN_PORT="${ADMIN_PORT:-5174}"

pids=()

cleanup() {
  for pid in "${pids[@]:-}"; do
    kill "$pid" 2>/dev/null || true
  done
}

trap cleanup EXIT INT TERM

echo "Starting API:      http://127.0.0.1:${SERVER_PORT}"
(
  cd "$PROJECT_ROOT/apps/server"
  "$PHP_BIN" think run -H 127.0.0.1 -p "$SERVER_PORT"
) &
pids+=("$!")

echo "Starting customer: http://127.0.0.1:${CUSTOMER_PORT}"
pnpm --dir "$PROJECT_ROOT/apps/customer-h5" dev --host 127.0.0.1 --port "$CUSTOMER_PORT" &
pids+=("$!")

echo "Starting admin:    http://127.0.0.1:${ADMIN_PORT}/admin/"
pnpm --dir "$PROJECT_ROOT/apps/admin-web" dev --host 127.0.0.1 --port "$ADMIN_PORT" &
pids+=("$!")

wait
