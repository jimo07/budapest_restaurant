#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HERD_PHP="/Users/jimo/Library/Application Support/Herd/bin/php"
HERD_COMPOSER="/Applications/Herd.app/Contents/Resources/composer"
PHP_BIN="$(command -v php || true)"
COMPOSER_BIN="$(command -v composer || true)"
[[ -n "$PHP_BIN" ]] || PHP_BIN="$HERD_PHP"
[[ -n "$COMPOSER_BIN" ]] || COMPOSER_BIN="$HERD_COMPOSER"

"$PHP_BIN" "$COMPOSER_BIN" --working-dir="$PROJECT_ROOT/apps/server" test
pnpm --dir "$PROJECT_ROOT" type-check
pnpm --dir "$PROJECT_ROOT" build

echo "Tests, type checks and production builds passed."
