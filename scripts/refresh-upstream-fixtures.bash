#!/usr/bin/env bash
# Re-fetches the pinned upstream jq and yq test material into the repository, so a refresh is one
# command and the vendored bytes are always reproducible from the recorded tag and commit.
#
# Usage: scripts/refresh-upstream-fixtures.bash [jq|yq|all]
#
# To move to a newer upstream release, change the pins below, run this, update the tag and commit in
# the matching fixtures/NOTICE.md, and update the expected case counts in the vendored-fixture tests.
set -euo pipefail

readonly JQ_REPO='https://github.com/jqlang/jq'
readonly JQ_TAG='jq-1.8.2'
readonly JQ_COMMIT='34f7186b86743a083a589741b6cea95293524108'

readonly YQ_REPO='https://github.com/mikefarah/yq'
readonly YQ_TAG='v4.54.1'
readonly YQ_COMMIT='504fc38780cc46be8444ea1b72fb55919fc0bfb0'

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
suite="${1:-all}"

case "$suite" in
    jq | yq | all) ;;
    *)
        echo "usage: ${0##*/} [jq|yq|all]" >&2
        exit 2
        ;;
esac

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# clone <repo> <tag> <expected commit> <destination>: shallow clone, then refuse a moved tag.
clone() {
    local repo="$1" tag="$2" commit="$3" dest="$4" actual
    git clone --quiet --depth 1 --branch "$tag" "$repo" "$dest"
    actual="$(git -C "$dest" rev-parse HEAD)"
    if [[ "$actual" != "$commit" ]]; then
        echo "error: $repo tag $tag is at $actual, expected $commit" >&2
        exit 1
    fi
}

refresh_jq() {
    local src="$work/jq" fixtures="$root/tests/Conformance/Jq" file
    clone "$JQ_REPO" "$JQ_TAG" "$JQ_COMMIT" "$src"

    for file in jq man onig uri manonig base64 optional; do
        cp -p "$src/tests/$file.test" "$fixtures/fixtures/$file.test"
    done
    cp -p "$src/COPYING" "$fixtures/fixtures/COPYING"

    rm -rf "$fixtures/modules"
    cp -pR "$src/tests/modules" "$fixtures/modules"
    # Not upstream: keeps git from rewriting the vendored bytes.
    printf '* -text -diff -whitespace\n' > "$fixtures/modules/.gitattributes"

    for file in shtest setup jq-f-test.sh no-main-program.jq yes-main-program.jq utf8test; do
        cp -p "$src/tests/$file" "$fixtures/shell/tests/$file"
    done
    rm -rf "$fixtures/shell/tests/torture"
    cp -pR "$src/tests/torture" "$fixtures/shell/tests/torture"

    echo "jq: refreshed from $JQ_TAG ($JQ_COMMIT)"
}

refresh_yq() {
    local src="$work/yq" fixtures="$root/tests/Conformance/Yq"
    clone "$YQ_REPO" "$YQ_TAG" "$YQ_COMMIT" "$src"

    cp -p "$src"/acceptance_tests/*.sh "$fixtures/acceptance/"
    cp -p "$src/scripts/shunit2" "$fixtures/acceptance/scripts/shunit2"
    cp -p "$src/LICENSE" "$fixtures/fixtures/LICENSE"
    php "$root/scripts/refresh-yq-fixtures.php" "$src"

    echo "yq: refreshed from $YQ_TAG ($YQ_COMMIT)"
}

if [[ "$suite" == jq || "$suite" == all ]]; then
    refresh_jq
fi
if [[ "$suite" == yq || "$suite" == all ]]; then
    refresh_yq
fi

echo "Review with: git status --short tests/Conformance"
