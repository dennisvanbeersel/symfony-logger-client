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
# (Flex applies it to that version and everything above, until the next higher
# version dir takes over). See SUBMIT-TO-CONTRIB.md.
#
# WHY TWO VERSION DIRS:
#   The canonical config carries `publishable_key:`, a node that ONLY exists in
#   the 2.1.0 Configuration. Flex would otherwise apply the recipe to 0.3–2.0.x
#   installs too, where `publishable_key` is an unrecognized option and
#   `cache:clear` FAILS. So we publish:
#     - 0.3  : base recipe WITHOUT publishable_key (applies to 0.3 … 2.0.x)
#     - 2.1  : full recipe WITH publishable_key   (applies to 2.1.0 and above)
#   Flex picks the highest version dir that is <= the installed version, so a
#   2.1+ install gets the 2.1 payload and an older install gets the 0.3 payload.
#
# Usage:  bash recipe/build-contrib.sh [version]
#   version defaults to 2.1 (the current line; the script always also emits the
#   0.3 base payload so older installs keep a clean, publishable_key-free recipe).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE="applogger/symfony-bundle"
VERSION="${1:-2.1}"

CONTRIB="$SCRIPT_DIR/contrib"
rm -rf "$CONTRIB"

# ---------------------------------------------------------------------------
# 2.1 payload — the canonical files verbatim (they carry publishable_key).
# ---------------------------------------------------------------------------
OUT_CURRENT="$CONTRIB/$PACKAGE/$VERSION"
mkdir -p "$OUT_CURRENT"
cp "$SCRIPT_DIR/manifest.json" "$OUT_CURRENT/manifest.json"
cp -R "$SCRIPT_DIR/config" "$OUT_CURRENT/config"

# ---------------------------------------------------------------------------
# 0.3 base payload — same files, but with the 2.1-only `publishable_key:` node
# (and its APPLICATION_LOGGER_PUBLISHABLE_KEY env var) stripped so the recipe is
# safe to apply to 0.3 … 2.0.x installs (where that option does not exist).
# ---------------------------------------------------------------------------
OUT_BASE="$CONTRIB/$PACKAGE/0.3"
mkdir -p "$OUT_BASE"

# manifest.json: drop the APPLICATION_LOGGER_PUBLISHABLE_KEY env entry, plus the
# post-install help lines that reference the publishable key (an option that does
# not exist before 2.1.0, so it would mislead 0.3–2.0.x installs).
python3 - "$SCRIPT_DIR/manifest.json" "$OUT_BASE/manifest.json" <<'PY'
import json, sys
src, dst = sys.argv[1], sys.argv[2]
with open(src) as f:
    data = json.load(f)
data.get("env", {}).pop("APPLICATION_LOGGER_PUBLISHABLE_KEY", None)
lines = data.get("post-install-output")
if isinstance(lines, list):
    data["post-install-output"] = [
        ln for ln in lines if "PUBLISHABLE_KEY" not in ln and "Publishable Key" not in ln
    ]
with open(dst, "w") as f:
    json.dump(data, f, indent=4)
    f.write("\n")
PY

# config/: copy the tree, then strip the publishable_key block from the yaml.
cp -R "$SCRIPT_DIR/config" "$OUT_BASE/config"
BASE_YAML="$OUT_BASE/config/packages/application_logger.yaml"
# Remove the publishable_key comment block + the `publishable_key:` line.
# Matches the canonical 4-line block (3 comment lines + the value line).
python3 - "$BASE_YAML" <<'PY'
import sys
path = sys.argv[1]
with open(path) as f:
    lines = f.readlines()

# Drop the contiguous publishable_key block: the leading "# Publishable Key …"
# comment lines through the `publishable_key:` value line, plus one trailing
# blank line. Operate line-by-line (NOT a DOTALL regex) so we can never swallow
# the rest of the file.
out = []
i = 0
removed = False
n = len(lines)
while i < n:
    stripped = lines[i].lstrip()
    if stripped.startswith("# Publishable Key"):
        # Consume the comment block up to and including the publishable_key: line.
        j = i
        while j < n and not lines[j].lstrip().startswith("publishable_key:"):
            j += 1
        if j < n:  # found the value line
            j += 1  # skip publishable_key: line itself
            # Drop a single trailing blank line that separated the block.
            if j < n and lines[j].strip() == "":
                j += 1
            i = j
            removed = True
            continue
    out.append(lines[i])
    i += 1

if not removed:
    sys.exit("ERROR: publishable_key block not found in base yaml; aborting so the "
             "0.3 payload is never published with a 2.1-only option.")

with open(path, "w") as f:
    f.writelines(out)
PY

echo "Assembled recipes-contrib payload:"
find "$CONTRIB" -type f | sed "s#^$CONTRIB/#  #" | sort
echo
echo "Next: see recipe/SUBMIT-TO-CONTRIB.md to open the PR."
