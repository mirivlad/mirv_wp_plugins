#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
while IFS= read -r f; do php -l "$f"; done < <(find plugins -type f -name '*.php' | sort)
while IFS= read -r f; do node --check "$f"; done < <(find plugins -type f -name '*.js' | sort)
for slug in mirv-projects mirv-category-blocks mirv-plugin-manager; do
  file="plugins/$slug/$slug.php"
  test -f "$file"
  grep -Fq 'Update URI: https://github.com/mirivlad/mirv_wp_plugins' "$file"
  version="$(sed -n -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$file" | head -1)"
  test -n "$version"
  bash scripts/package.sh "$slug-v$version"
  unzip -Z -1 "dist/$slug.zip" | grep -Fxq "$slug/$slug.php"
  (cd dist && sha256sum -c "$slug.zip.sha256")
done
echo "All PHP, JS, package and checksum checks passed"
