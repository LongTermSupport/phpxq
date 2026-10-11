#!/usr/bin/env bash
# Release preflight: decides whether the current commit may be released.
#
# Reads VERSION (the single source of truth), validates it as a semantic version, requires it to agree
# with the newest CHANGELOG.md section, and refuses when the tag vX.Y.Z already exists locally or on the
# remote. Prints `releasable=`, `version=` and `tag=` lines (appended to $GITHUB_OUTPUT
# too when set). A commit whose `## Unreleased` still has entries is not a release commit: it prints
# `releasable=false` and exits 0 so the workflow skips every later stage. Exits non-zero, loudly, on any
# other problem.
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

# VERSION, the newest CHANGELOG.md section and the tags must agree (scripts/release.php verify): exit 0
# releasable, 3 this is not a release commit (`## Unreleased` still has entries, as on the first push that
# creates the branch), anything else a refusal whose reason the command printed.
verify_status=0
"$root/scripts/release.bash" verify || verify_status=$?
case "$verify_status" in
    0) ;;
    3)
        echo "::notice title=Not a release commit::'## Unreleased' still has entries, so nothing is released from this commit. Releases come from the release pull request (docs/RELEASING.md)."
        echo "releasable=false"
        if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
            echo "releasable=false" >>"$GITHUB_OUTPUT"
        fi
        exit 0
        ;;
    *) fail_loud "the VERSION, CHANGELOG.md and tag check refused this release (reason above)" ;;
esac

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

echo "releasable=true"
echo "version=$version"
echo "tag=$tag"
if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
    {
        echo "releasable=true"
        echo "version=$version"
        echo "tag=$tag"
    } >>"$GITHUB_OUTPUT"
fi
echo "Preflight passed: $tag is free" >&2
