#!/usr/bin/env bash
# Builds a self-contained phpxq executable: a static PHP "micro" runtime (static-php-cli) with the PHAR
# appended. The result needs no PHP on the target machine.
#
# Usage: scripts/build-binary.bash [--phar FILE] [--platform OS-ARCH] [--output FILE] [--skip-doctor]
#   --phar FILE        PHAR to embed (default dist/phpxq.phar; build it with scripts/build-phar.bash)
#   --platform OS-ARCH linux-x86_64 | linux-aarch64 | macos-x86_64 | macos-aarch64 (default: this host).
#                      static-php-cli compiles natively, so this must match the host.
#   --output FILE      binary to write (default dist/phpxq-<platform>)
#   --skip-doctor      do not run `spc doctor --auto-fix` (the host toolchain is already prepared)
#
# Environment:
#   SPC_BIN            use this spc binary instead of downloading the pinned release
#   PHPXQ_TOOLS_DIR    where pinned tools are cached (default untracked/tools)
#   PHPXQ_SPC_WORKDIR  static-php-cli workspace (default build/spc-<platform>); cache it between runs
#                      to skip re-downloading and re-compiling PHP
set -euo pipefail

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/packaging.bash
source "$(dirname "${BASH_SOURCE[0]}")/lib/packaging.bash"

phar="$root/dist/phpxq.phar"
platform=""
output=""
skip_doctor=0
while (($# > 0)); do
    case "$1" in
        --phar)
            [[ -n "${2:-}" ]] || die "--phar needs a file"
            phar="$2"
            shift
            ;;
        --platform)
            [[ -n "${2:-}" ]] || die "--platform needs a value"
            platform="$2"
            shift
            ;;
        --output)
            [[ -n "${2:-}" ]] || die "--output needs a file"
            output="$2"
            shift
            ;;
        --skip-doctor) skip_doctor=1 ;;
        -h | --help)
            awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

if [[ -z "$platform" ]]; then
    case "$(uname -s)" in
        Linux) os=linux ;;
        Darwin) os=macos ;;
        *) die "unsupported host OS $(uname -s); use Linux or macOS" ;;
    esac
    case "$(uname -m)" in
        x86_64 | amd64) arch=x86_64 ;;
        aarch64 | arm64) arch=aarch64 ;;
        *) die "unsupported host architecture $(uname -m)" ;;
    esac
    platform="$os-$arch"
fi
case "$platform" in
    linux-x86_64 | linux-aarch64 | macos-x86_64 | macos-aarch64) ;;
    *) die "unsupported platform '$platform'" ;;
esac

[[ -f "$phar" ]] || die "PHAR not found: $phar (run scripts/build-phar.bash first)"
phar="$(cd "$(dirname "$phar")" && pwd)/$(basename "$phar")"
[[ -n "$output" ]] || output="$root/dist/phpxq-$platform"
mkdir -p "$(dirname "$output")"
output="$(cd "$(dirname "$output")" && pwd)/$(basename "$output")"

spc="${SPC_BIN:-}"
if [[ -z "$spc" ]]; then
    checksum_var="SPC_SHA256_${platform//-/_}"
    spc_dir="$tools_dir/spc-$SPC_VERSION-$platform"
    mkdir -p "$spc_dir"
    download_verified \
        "https://github.com/crazywhalecc/static-php-cli/releases/download/$SPC_VERSION/spc-$platform.tar.gz" \
        "${!checksum_var}" "$spc_dir/spc.tar.gz"
    tar -xzf "$spc_dir/spc.tar.gz" -C "$spc_dir"
    chmod +x "$spc_dir/spc"
    spc="$spc_dir/spc"
fi
[[ -x "$spc" ]] || die "spc is not executable: $spc"

extensions="$(php "$root/scripts/lib/extensions.php" "$root/packaging/extensions.txt" "$root/composer.json")"
workdir="${PHPXQ_SPC_WORKDIR:-$root/build/spc-$platform}"
mkdir -p "$workdir"

echo "Platform:   $platform"
echo "spc:        $("$spc" --version --no-ansi)"
echo "PHP:        $SPC_PHP_VERSION"
echo "Extensions: $extensions"
echo "Workspace:  $workdir"

cd "$workdir"
if ((skip_doctor == 0)); then
    "$spc" doctor --auto-fix --no-interaction
fi
"$spc" download --for-extensions="$extensions" --with-php="$SPC_PHP_VERSION" --prefer-pre-built --retry=3 --no-interaction
"$spc" build "$extensions" --build-micro --no-interaction
[[ -f buildroot/bin/micro.sfx ]] || die "spc did not produce buildroot/bin/micro.sfx"

# memory_limit is lifted because a jq/yq filter over a large document must not hit PHP's 128M default.
"$spc" micro:combine "$phar" --output="$output" --with-ini-set="memory_limit=-1" --no-interaction
[[ -f "$output" ]] || die "spc micro:combine did not write $output"
chmod 755 "$output"

echo "Built $output ($(wc -c <"$output") bytes)"
echo "sha256 $(sha256_of "$output")  $output"
