#!/usr/bin/env bash
# Syntax-check every PHP file, and the JavaScript modules when Node is available.
set -euo pipefail
cd "$(dirname "$0")/.."

status=0
while IFS= read -r -d '' f; do
  if ! out=$(php -l "$f" 2>&1); then
    echo "$out"; status=1
  fi
done < <(find src public tests bin config.php -type f \( -name '*.php' -o -path 'bin/*' \) -print0)

if command -v node >/dev/null 2>&1; then
  while IFS= read -r -d '' f; do
    # Node 22+ detects ES-module syntax on its own.
    node --check "$f" || status=1
  done < <(find public/assets/js -name '*.js' -print0)
else
  echo "node not found: skipping JavaScript syntax check" >&2
fi

[ $status -eq 0 ] && echo "lint ok"
exit $status
