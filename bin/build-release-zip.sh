#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="clockwork-companion"
OUT_DIR="${OUT_DIR:-$ROOT/dist}"
STAGE="$OUT_DIR/$SLUG"
ZIP="$OUT_DIR/$SLUG.zip"

rm -rf "$STAGE" "$ZIP" "$ZIP.sha256"
mkdir -p "$STAGE"

for item in \
    clockwork-companion.php \
    LICENSE \
    README.md \
    CHANGELOG.md \
    src \
    assets
do
    [ -e "$ROOT/$item" ] && cp -R "$ROOT/$item" "$STAGE/"
done

find "$STAGE" -name '.DS_Store' -delete

(cd "$OUT_DIR" && zip -rq "$ZIP" "$SLUG")
rm -rf "$STAGE"

if command -v sha256sum >/dev/null 2>&1; then
    (cd "$OUT_DIR" && sha256sum "$(basename "$ZIP")" > "$ZIP.sha256")
else
    (cd "$OUT_DIR" && shasum -a 256 "$(basename "$ZIP")" > "$ZIP.sha256")
fi

echo "Built $ZIP"
