#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "== php -l =="
find . -name '*.php' -not -path './languages/*' | while read -r f; do
  php -l "$f" >/dev/null
done
echo "Syntax OK"

echo "== parser tests =="
php tests/run-tests.php
