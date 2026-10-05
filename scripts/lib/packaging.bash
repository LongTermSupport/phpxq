#!/usr/bin/env bash
# Shared helpers for the packaging scripts. Source it, do not execute it.
#
# Provides: root, tools_dir, die, sha256_of, download_verified, release_version, plus every variable
# from packaging/tools.env.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tools_dir="${PHPXQ_TOOLS_DIR:-$root/untracked/tools}"
export root tools_dir

# shellcheck source-path=SCRIPTDIR/../..
# shellcheck source=packaging/tools.env
source "$root/packaging/tools.env"

die() {
    echo "ERROR: $*" >&2
    exit 1
}

# sha256_of <file>: prints the hex digest, using whichever tool the platform has.
sha256_of() {
    if command -v sha256sum >/dev/null; then
        sha256sum "$1" | awk '{print $1}'
    elif command -v shasum >/dev/null; then
        shasum -a 256 "$1" | awk '{print $1}'
    else
        die "neither sha256sum nor shasum is available"
    fi
}

# download_verified <url> <sha256> <dest>: fetches the file unless dest already matches the checksum,
# and refuses to leave a file with the wrong checksum behind.
download_verified() {
    local url="$1" expected="$2" dest="$3" actual
    mkdir -p "$(dirname "$dest")"
    if [[ -f "$dest" && "$(sha256_of "$dest")" == "$expected" ]]; then
        return 0
    fi
    echo "Downloading $url" >&2
    curl --fail --silent --show-error --location --retry 3 --output "$dest" "$url"
    actual="$(sha256_of "$dest")"
    if [[ "$actual" != "$expected" ]]; then
        rm -f "$dest"
        die "checksum mismatch for $url: expected $expected, got $actual"
    fi
}

# release_version: the single source of truth, the VERSION file, validated as MAJOR.MINOR.PATCH[-pre].
release_version() {
    local version
    [[ -f "$root/VERSION" ]] || die "VERSION file is missing"
    version="$(tr -d '[:space:]' <"$root/VERSION")"
    [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || die "VERSION '$version' is not a semantic version"
    printf '%s\n' "$version"
}

# remote_branch_exists <branch>: 0 when origin has the branch, 1 when it has not; any other outcome of
# `git ls-remote` (network, auth) is a real failure and must not be mistaken for "absent".
remote_branch_exists() {
    local status=0
    git -C "$root" ls-remote --exit-code --heads origin "$1" >/dev/null || status=$?
    case "$status" in
        0) return 0 ;;
        2) return 1 ;;
        *) die "could not query origin for branch $1 (git ls-remote exit $status)" ;;
    esac
}
