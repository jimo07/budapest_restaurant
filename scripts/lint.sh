#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HERD_PHP="/Users/jimo/Library/Application Support/Herd/bin/php"
PHP_BIN="$(command -v php || true)"
[[ -n "$PHP_BIN" ]] || PHP_BIN="$HERD_PHP"

while IFS= read -r -d '' php_file; do
  "$PHP_BIN" -l "$php_file" >/dev/null
done < <(find "$PROJECT_ROOT/apps/server/app" "$PROJECT_ROOT/apps/server/config" "$PROJECT_ROOT/apps/server/route" "$PROJECT_ROOT/apps/server/tests" -type f -name '*.php' -print0)

pnpm --dir "$PROJECT_ROOT/apps/customer-h5" exec oxlint .
pnpm --dir "$PROJECT_ROOT/apps/customer-h5" exec eslint . --cache
pnpm --dir "$PROJECT_ROOT/apps/admin-web" exec oxlint .
pnpm --dir "$PROJECT_ROOT/apps/admin-web" exec eslint . --cache
pnpm --dir "$PROJECT_ROOT" type-check

echo "Lint and static checks passed."
