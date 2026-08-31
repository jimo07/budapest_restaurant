#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HERD_PHP="/Users/jimo/Library/Application Support/Herd/bin/php"
PHP_BIN="$(command -v php || true)"
[[ -n "$PHP_BIN" ]] || PHP_BIN="$HERD_PHP"

"$PHP_BIN" "$PROJECT_ROOT/apps/server/scripts/install.php" --seed --translations-demo
