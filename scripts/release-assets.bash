#!/usr/bin/env bash
# Assembles the release asset directory: verifies the expected artefacts are all present and non-empty,
# adds install.sh, and writes SHA256SUMS covering every asset.
#
# Usage: scripts/release-assets.bash <asset-dir>
#
# Required assets (the release is refused without them):
#   phpxq.phar  phpxq-linux-x86_64  phpxq-linux-aarch64
# Optional assets (a missing one is a loud warning, not a refusal; see docs/RELEASING.md):
#   phpxq-macos-x86_64  phpxq-macos-aarch64
set -euo pipefail

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/packaging.bash
source "$(dirname "${BASH_SOURCE[0]}")/lib/packaging.bash"

required=(phpxq.phar phpxq-linux-x86_64 phpxq-linux-aarch64)
optional=(phpxq-macos-x86_64 phpxq-macos-aarch64)

[[ $# -eq 1 ]] || die "Usage:${0##*/} <asset-dir>"
dir="$1"
[[ -d "$dir" ]] || die "asset directory not found: $dir"

missing=()
for asset in "${required[@]}"; do
    [[ -s "$dir/$asset" ]] || missing+=("$asset")
done
if ((${#missing[@]} > 0)); then
    echo "::error title=Release assets incomplete::missing required asset(s): ${missing[*]}"
    die "missing required asset(s) in $dir: ${missing[*]}"
fi

for asset in "${optional[@]}"; do
    if [[ ! -s "$dir/$asset" ]]; then
        echo "::warning title=Optional asset missing::$asset was not built; the release ships without it"
        echo "WARNING: optional asset missing: $asset" >&2
    fi
done

cp "$root/install.sh" "$dir/install.sh"

(
    cd "$dir"
    rm -f SHA256SUMS
    : >SHA256SUMS
    for asset in $(find . -maxdepth 1 -type f ! -name SHA256SUMS -exec basename {} \; | LC_ALL=C sort); do
        printf '%s  %s\n' "$(sha256_of "$asset")" "$asset" >>SHA256SUMS
    done
)

echo "Release assets in $dir:"
cat "$dir/SHA256SUMS"
