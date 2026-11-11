#!/usr/bin/env bash
# Builds the Odoo 18.0 flavour of the module into ./dist/18.0/textmeflow.
# Differences from 17.0: manifest version, and <tree> views were renamed <list>.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
out="$here/dist/18.0/textmeflow"
rm -rf "$out"
mkdir -p "$out"
cp -R "$here/textmeflow/." "$out/"
find "$out" -name __pycache__ -prune -exec rm -rf {} +
sed -i.bak 's/"17\.0\.1\.0\.0"/"18.0.1.0.0"/' "$out/__manifest__.py"
for f in $(grep -rl --include='*.xml' '<tree' "$out"); do
  sed -i.bak -e 's/<tree/<list/g' -e 's#</tree>#</list>#g' -e 's#<field name="view_mode">tree,form#<field name="view_mode">list,form#' "$f"
done
find "$out" -name '*.bak' -delete
echo "Built $out"
