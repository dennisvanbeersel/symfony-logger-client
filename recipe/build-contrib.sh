#!/usr/bin/env bash
#
# Assemble the symfony/recipes-contrib submission payload from this bundle's
# canonical recipe/ files (single source of truth).
#
# recipes-contrib expects, at the repo root:
#
#   <vendor>/<package>/<version>/manifest.json
#   <vendor>/<package>/<version>/config/...        (files referenced by copy-from-recipe)
#
# The <version> is the LOWEST released package version the recipe applies to
# (Flex applies it to that version and everything above). See SUBMIT-TO-CONTRIB.md.
#
# Usage:  bash recipe/build-contrib.sh [version]
#   version defaults to 0.3 (the first released version carrying this recipe).
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE="applogger/symfony-bundle"
VERSION="${1:-0.3}"

OUT="$SCRIPT_DIR/contrib/$PACKAGE/$VERSION"

rm -rf "$SCRIPT_DIR/contrib"
mkdir -p "$OUT"

# manifest.json is already in the exact Flex format contrib consumes.
cp "$SCRIPT_DIR/manifest.json" "$OUT/manifest.json"

# Everything the manifest's copy-from-recipe block references lives under config/.
cp -R "$SCRIPT_DIR/config" "$OUT/config"

echo "Assembled recipes-contrib payload:"
find "$SCRIPT_DIR/contrib" -type f | sed "s#^$SCRIPT_DIR/contrib/#  #"
echo
echo "Next: see recipe/SUBMIT-TO-CONTRIB.md to open the PR."
