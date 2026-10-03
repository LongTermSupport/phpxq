#!/bin/sh
# phpxq installer: downloads a release binary and verifies it against the release's SHA256SUMS.
#
#   curl -fsSL https://github.com/LongTermSupport/php-xq/releases/latest/download/install.sh | sh
#   curl -fsSL https://github.com/LongTermSupport/php-xq/releases/latest/download/install.sh | sh -s -- --links
#
# Options (or the matching environment variable):
#   --version X.Y.Z   release to install              PHPXQ_VERSION      (default: latest)
#   --dir DIR         where to install                PHPXQ_INSTALL_DIR  (default: $HOME/.local/bin)
#   --links           also create jq and yq links     PHPXQ_LINKS=1      (off by default: they would
#                                                                         shadow a real jq or yq)
#   --phar            install the PHAR (needs PHP 8.5 on the machine) instead of the static binary
#
# Other environment: PHPXQ_REPO (owner/name), PHPXQ_BASE_URL (download root, for mirrors and tests).
# Nothing is installed unless the SHA-256 of the download matches the published checksum.
set -eu

repo="${PHPXQ_REPO:-LongTermSupport/php-xq}"
version="${PHPXQ_VERSION:-latest}"
install_dir="${PHPXQ_INSTALL_DIR:-${HOME:-.}/.local/bin}"
links="${PHPXQ_LINKS:-0}"
use_phar=0
base_url="${PHPXQ_BASE_URL:-}"

say() { printf '%s\n' "$*"; }
fail() {
    printf 'install.sh: error: %s\n' "$*" >&2
    exit 1
}

while [ $# -gt 0 ]; do
    case "$1" in
        --version)
            [ $# -ge 2 ] || fail "--version needs a value"
            version="$2"
            shift
            ;;
        --dir)
            [ $# -ge 2 ] || fail "--dir needs a value"
            install_dir="$2"
            shift
            ;;
        --links) links=1 ;;
        --phar) use_phar=1 ;;
        -h | --help)
            say "usage: install.sh [--version X.Y.Z] [--dir DIR] [--links] [--phar]"
            say "see the header of install.sh for the environment variables"
            exit 0
            ;;
        *) fail "unknown option: $1" ;;
    esac
    shift
done

version="${version#v}"

if [ -z "$base_url" ]; then
    if [ "$version" = latest ]; then
        base_url="https://github.com/$repo/releases/latest/download"
    else
        base_url="https://github.com/$repo/releases/download/v$version"
    fi
fi

if [ "$use_phar" -eq 1 ]; then
    asset="phpxq.phar"
    command -v php >/dev/null || fail "--phar needs php on the PATH"
else
    case "$(uname -s)" in
        Linux) os=linux ;;
        Darwin) os=macos ;;
        *) fail "unsupported operating system $(uname -s); use --phar if you have PHP 8.5" ;;
    esac
    case "$(uname -m)" in
        x86_64 | amd64) arch=x86_64 ;;
        aarch64 | arm64) arch=aarch64 ;;
        *) fail "unsupported architecture $(uname -m); use --phar if you have PHP 8.5" ;;
    esac
    asset="phpxq-$os-$arch"
fi

if command -v curl >/dev/null; then
    fetch() { curl --fail --silent --show-error --location --retry 3 --output "$2" "$1"; }
elif command -v wget >/dev/null; then
    fetch() { wget --quiet --output-document="$2" "$1"; }
else
    fail "curl or wget is required"
fi

if command -v sha256sum >/dev/null; then
    sha256() { sha256sum "$1" | cut -d ' ' -f 1; }
elif command -v shasum >/dev/null; then
    sha256() { shasum -a 256 "$1" | cut -d ' ' -f 1; }
else
    fail "sha256sum or shasum is required to verify the download"
fi

tmp="$(mktemp -d "${TMPDIR:-/tmp}/phpxq-install.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT INT TERM

say "Downloading $asset ($version) from $base_url"
fetch "$base_url/SHA256SUMS" "$tmp/SHA256SUMS" || fail "could not download SHA256SUMS (does release '$version' exist?)"
fetch "$base_url/$asset" "$tmp/$asset" || fail "could not download $asset"

expected="$(awk -v name="$asset" '$2 == name || $2 == "*" name { print $1; exit }' "$tmp/SHA256SUMS")"
[ -n "$expected" ] || fail "SHA256SUMS has no entry for $asset"
actual="$(sha256 "$tmp/$asset")"
if [ "$expected" != "$actual" ]; then
    fail "checksum mismatch for $asset: expected $expected, got $actual. Nothing was installed."
fi
say "Checksum verified ($actual)"

mkdir -p "$install_dir"
chmod 755 "$tmp/$asset"
# A same-directory rename keeps the replacement atomic, so an interrupted install never leaves a partial binary.
cp "$tmp/$asset" "$install_dir/.phpxq.new"
mv -f "$install_dir/.phpxq.new" "$install_dir/phpxq"
say "Installed $install_dir/phpxq"

if [ "$links" = 1 ]; then
    for tool in jq yq; do
        target="$install_dir/$tool"
        if [ -e "$target" ] && [ ! -L "$target" ]; then
            say "Skipped $target: a regular file already exists there"
            continue
        fi
        ln -sf phpxq "$target"
        say "Linked $target -> phpxq"
    done
fi

"$install_dir/phpxq" --version
case ":${PATH:-}:" in
    *":$install_dir:"*) ;;
    *) say "Note: $install_dir is not on your PATH. Add it, for example: export PATH=\"$install_dir:\$PATH\"" ;;
esac
