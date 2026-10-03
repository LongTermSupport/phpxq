#!/usr/bin/env bash
# Release preflight: decides whether the current commit may be released.
#
# Reads VERSION (the single source of truth), validates it as a semantic version, and refuses when the
# tag vX.Y.Z already exists locally or on the remote. Prints `version=`, `tag=` and `prerelease=` lines
# (appended to $GITHUB_OUTPUT too when set). Exits non-zero, loudly, on any problem.
#
# Usage: scripts/release-preflight.bash [--remote NAME]   (default remote: origin)
set -euo pipefail

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/packaging.bash
source "$(dirname "${BASH_SOURCE[0]}")/lib/packaging.bash"

remote=origin
while (($# > 0)); do
    case "$1" in
        --remote)
            [[ -n "${2:-}" ]] || die "--remote needs a name"
            remote="$2"
            shift
            ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

fail_loud() {
    echo "::error title=Release refused::$*"
    die "$*"
}

version="$(release_version)" || fail_loud "VERSION is missing or not a semantic version"
tag="v$version"

if git -C "$root" rev-parse --verify --quiet "refs/tags/$tag" >/dev/null; then
    fail_loud "tag $tag already exists locally. Bump VERSION before releasing."
fi

# ls-remote exits 0 with output when the tag exists, 2 when it does not; any other status is a real
# failure (network, auth) and must not be mistaken for "the tag is free".
remote_status=0
remote_refs="$(git -C "$root" ls-remote --exit-code --tags "$remote" "refs/tags/$tag" 2>&1)" || remote_status=$?
case "$remote_status" in
    0) fail_loud "tag $tag already exists on $remote ($remote_refs). Bump VERSION before releasing." ;;
    2) ;;
    *) fail_loud "could not query tags on $remote (exit $remote_status): $remote_refs" ;;
esac

prerelease=false
[[ "$version" == *-* ]] && prerelease=true

echo "version=$version"
echo "tag=$tag"
echo "prerelease=$prerelease"
if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
    {
        echo "version=$version"
        echo "tag=$tag"
        echo "prerelease=$prerelease"
    } >>"$GITHUB_OUTPUT"
fi
echo "Preflight passed: $tag is free" >&2
