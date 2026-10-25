#!/usr/bin/env bash
set -euo pipefail
tag="${1:-}"
if [[ "$tag" =~ ^(mirv-projects|mirv-category-blocks|mirv-plugin-manager)-v([0-9]+\.[0-9]+\.[0-9]+)$ ]]; then
  slug="${BASH_REMATCH[1]}"
  version="${BASH_REMATCH[2]}"
else
  echo "Invalid release tag: $tag" >&2
  exit 2
fi
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin="$root/plugins/$slug"
main="$plugin/$slug.php"
[[ -f "$main" ]] || { echo "Missing main file: $main" >&2; exit 3; }
header_version="$(sed -n -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$main" | head -1)"
[[ "$version" == "$header_version" ]] || { echo "Tag $version disagrees with plugin version $header_version" >&2; exit 4; }
grep -Fq 'Update URI: https://github.com/mirivlad/mirv_wp_plugins' "$main" || {
  echo "Missing Update URI header in $main" >&2; exit 5;
}
mkdir -p "$root/dist"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
cp -a "$plugin" "$stage/$slug"
find "$stage/$slug" -name '*.log' -delete
find "$stage/$slug" -name '.DS_Store' -delete
(
  cd "$stage"
  zip -q -r "$root/dist/$slug.zip" "$slug"
)
(
  cd "$root/dist"
  sha256sum "$slug.zip" > "$slug.zip.sha256"
)
echo "Packaged $tag"
unzip -Z -1 "$root/dist/$slug.zip" | head -10
cat "$root/dist/$slug.zip.sha256"
