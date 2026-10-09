#!/usr/bin/env bash
# Runs the full QA pipeline locally with mutation testing scoped to the change, as qa.yml does in CI: scopes
# Infection against <base-ref>, runs `CI=true vendor/bin/qa`, then the measurement floors. The generated
# qaConfig/infection.json (and any stray root .yml Infection leaves) is always removed afterwards, so a later plain
# `CI=true vendor/bin/qa` is still the full run. Rules: docs/RELEASING.md ("Mutation testing in CI").
#
# Usage: scripts/qa-scoped.bash [<base-ref>] [-- <extra vendor/bin/qa arguments>]
# Base: the first argument, else $PHPXQ_MUTATION_BASE, else origin/main; the merge base with HEAD is used. Nothing is
# fetched. $PHPXQ_QA_BIN replaces vendor/bin/qa (the test stubs it).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

requested=""
if [[ $# -gt 0 && "$1" != "--" ]]; then
    requested="$1"
    shift
fi
if [[ "${1:-}" == "--" ]]; then
    shift
fi
requested="${requested:-${PHPXQ_MUTATION_BASE:-origin/main}}"

if ! git rev-parse --verify --quiet "${requested}^{commit}" >/dev/null; then
    echo "qa-scoped: base ref '$requested' does not exist here (nothing is fetched); fetch it or pass another base" >&2
    exit 1
fi
if ! base="$(git merge-base "$requested" HEAD)"; then
    echo "qa-scoped: no merge base between '$requested' and HEAD" >&2
    exit 1
fi
echo "qa-scoped: mutation base $requested (merge base ${base:0:12})"

# Root .yml files present before the run are not ours to remove.
declare -A preexisting=()
for file in "$root"/*.yml; do
    [[ -e "$file" ]] && preexisting["$file"]=1
done

cleanup() {
    rm -f "$root/qaConfig/infection.json"
    for file in "$root"/*.yml; do
        if [[ -e "$file" && -z "${preexisting[$file]:-}" ]]; then
            rm -f "$file"
        fi
    done
}
trap cleanup EXIT

scope_output="$(scripts/mutation-scope.bash "$base" --write)"
echo "$scope_output"

# Nothing to mutate: switch the lane off, as the workflow does.
if grep -qx 'scope=none' <<<"$scope_output"; then
    export PHPXQ_INFECTION_SKIP=1
fi

CI=true "${PHPXQ_QA_BIN:-vendor/bin/qa}" "$@"

PHPXQ_MUTATION_BASE="$base" scripts/check-qa-measurements.bash
