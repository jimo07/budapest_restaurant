#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HERD_PHP="/Users/jimo/Library/Application Support/Herd/bin/php"
HERD_COMPOSER="/Applications/Herd.app/Contents/Resources/composer"
PHP_BIN="$(command -v php || true)"
COMPOSER_BIN="$(command -v composer || true)"
[[ -n "$PHP_BIN" ]] || PHP_BIN="$HERD_PHP"
[[ -n "$COMPOSER_BIN" ]] || COMPOSER_BIN="$HERD_COMPOSER"

[[ -x "$PHP_BIN" ]] || { echo "PHP is required." >&2; exit 1; }
[[ -f "$COMPOSER_BIN" ]] || { echo "Composer is required." >&2; exit 1; }
command -v pnpm >/dev/null || { echo "pnpm is required." >&2; exit 1; }

if [[ ! -f "$PROJECT_ROOT/apps/server/.env" ]]; then
  cp "$PROJECT_ROOT/apps/server/.example.env" "$PROJECT_ROOT/apps/server/.env"
  echo "Created apps/server/.env; configure its database before seeding."
fi

if [[ ! -f "$PROJECT_ROOT/apps/customer-h5/.env" ]]; then
  cp "$PROJECT_ROOT/apps/customer-h5/.env.example" "$PROJECT_ROOT/apps/customer-h5/.env"
fi

if [[ ! -f "$PROJECT_ROOT/apps/admin-web/.env" ]]; then
  cp "$PROJECT_ROOT/apps/admin-web/.env.example" "$PROJECT_ROOT/apps/admin-web/.env"
fi

"$PHP_BIN" "$COMPOSER_BIN" install --working-dir="$PROJECT_ROOT/apps/server"
pnpm --dir "$PROJECT_ROOT" install

echo "Dependencies are ready. Configure MySQL, then run: pnpm seed"
