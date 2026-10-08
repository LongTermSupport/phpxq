#!/usr/bin/env bash
# Scopes the Infection lane to the source a change could have weakened the testing of: feeds the change since
# <base-ref> (merge base, renames detected) to scripts/mutation-scope.php. The rules are in
# scripts/Qa/MutationScope.php; the CI wiring is in docs/RELEASING.md ("Mutation testing in CI").
#
# Usage: scripts/mutation-scope.bash <base-ref> [--write]
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
base="${1:?usage: scripts/mutation-scope.bash <base-ref> [--write]}"
shift

# -z: NUL-separated and never C-quoted, so a path with a space, tab or non-ASCII byte reaches the PHP side verbatim.
# Kept in a file, not a variable, which would drop the NULs. The diff must succeed before PHP sees anything: an empty
# diff from a failed git would read as "no change" (scope none), so a failure reports scope all and exits 1 instead.
diff_file="$(mktemp)"
trap 'rm -f "$diff_file"' EXIT
if ! git -C "$root" diff -z --name-status -M "$base...HEAD" >"$diff_file"; then
    echo "mutation-scope: git cannot diff against $base; scope=all" >&2
    if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
        echo "scope=all" >>"$GITHUB_OUTPUT"
    fi
    exit 1
fi
php "$root/scripts/mutation-scope.php" "$@" <"$diff_file"
