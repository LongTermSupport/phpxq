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
# Piped, not captured: command substitution would drop the NULs. pipefail makes a failed diff fail the script.
git -C "$root" diff -z --name-status -M "$base...HEAD" | php "$root/scripts/mutation-scope.php" "$@"
