#!/usr/bin/env bash
# Builds the installable archive expected by the PrestaShop module manager.
set -euo pipefail

MODULE="microsoftadsfeed"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST="$ROOT/dist"

rm -rf "$DIST"
mkdir -p "$DIST"

cd "$ROOT"
zip -r -q "$DIST/$MODULE.zip" "$MODULE" \
    -x "$MODULE/export/feed-*" \
    -x "$MODULE/export/changed" \
    -x "$MODULE/export/build.lock"

echo "Built $DIST/$MODULE.zip"
