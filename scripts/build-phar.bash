#!/usr/bin/env bash
# Builds the phpxq PHAR with Box from a clean staging tree, so the result depends only on committed
# sources: production Composer install (no dev packages), pinned Box, pinned timestamp.
#
# Usage: scripts/build-phar.bash [--output FILE] [--check-reproducible]
#   --output FILE          where to write the PHAR (default dist/phpxq.phar)
#   --check-reproducible   build twice from scratch and fail unless both PHARs are byte-identical
#
# Environment:
#   SOURCE_DATE_EPOCH   build timestamp (default: the HEAD commit time, else the VERSION file mtime)
#   BOX_PHAR            use this box.phar instead of downloading the pinned one (still version-checked)
#   PHPXQ_TOOLS_DIR     where pinned tools are cached (default untracked/tools)
set -euo pipefail

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/packaging.bash
source "$(dirname "${BASH_SOURCE[0]}")/lib/packaging.bash"

output="$root/dist/phpxq.phar"
check_reproducible=0
while (($# > 0)); do
    case "$1" in
        --output)
            [[ -n "${2:-}" ]] || die "--output needs a file"
            output="$2"
            shift
            ;;
        --check-reproducible) check_reproducible=1 ;;
        -h | --help)
            awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

version="$(release_version)"

epoch="${SOURCE_DATE_EPOCH:-}"
if [[ -z "$epoch" ]]; then
    epoch="$(git -C "$root" log -1 --format=%ct 2>/dev/null)" || epoch=""
fi
if [[ -z "$epoch" ]]; then
    epoch="$(stat -c %Y "$root/VERSION" 2>/dev/null || stat -f %m "$root/VERSION")"
fi
[[ "$epoch" =~ ^[0-9]+$ ]] || die "SOURCE_DATE_EPOCH '$epoch' is not an integer"
timestamp="$(date -u -d "@$epoch" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -u -r "$epoch" '+%Y-%m-%d %H:%M:%S')"

box="${BOX_PHAR:-}"
if [[ -z "$box" ]]; then
    box="$tools_dir/box-$BOX_VERSION.phar"
    download_verified "https://github.com/box-project/box/releases/download/$BOX_VERSION/box.phar" "$BOX_SHA256" "$box"
fi
[[ -f "$box" ]] || die "Box PHAR not found: $box"
box="$(cd "$(dirname "$box")" && pwd)/$(basename "$box")"
box_reported="$(php "$box" --version --no-ansi)"
[[ "$box_reported" == *"$BOX_VERSION"* ]] || die "Box reports '$box_reported', expected version $BOX_VERSION"

# build_once <output-file>: stage, install production dependencies, compile.
build_once() {
    local out="$1" stage
    stage="$(mktemp -d "${TMPDIR:-/tmp}/phpxq-stage.XXXXXX")"
    mkdir -p "$stage/bin"
    cp "$root/bin/phpxq" "$stage/bin/phpxq"
    cp -R "$root/src" "$stage/src"
    cp "$root/VERSION" "$root/composer.json" "$root/composer.lock" "$stage/"

    php "$root/scripts/lib/box-config.php" "$root/box.json" "$stage/phpxq.phar" "$epoch" "$stage/box.json"

    (
        cd "$stage"
        PHP_QA_CI_DISABLE_CONFIG_PUSH=true composer install --no-dev --no-interaction --no-progress \
            --no-scripts --no-plugins --optimize-autoloader --classmap-authoritative --quiet
        php -d phar.readonly=0 "$box" compile --no-interaction --no-ansi --quiet --config="$stage/box.json"
    )

    mkdir -p "$(dirname "$out")"
    cp "$stage/phpxq.phar" "$out"
    chmod 755 "$out"
    rm -rf "$stage"
}

echo "Building phpxq $version (timestamp $timestamp UTC) -> $output"
build_once "$output"

if ((check_reproducible)); then
    second="$(mktemp "${TMPDIR:-/tmp}/phpxq-second.XXXXXX")"
    build_once "$second"
    if ! cmp -s "$output" "$second"; then
        echo "first : $(sha256_of "$output")" >&2
        echo "second: $(sha256_of "$second")" >&2
        rm -f "$second"
        die "the PHAR build is not reproducible"
    fi
    rm -f "$second"
    echo "Reproducible: two clean builds are byte-identical"
fi

echo "sha256 $(sha256_of "$output")  $output"
