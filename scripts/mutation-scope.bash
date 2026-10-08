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

diff="$(git -C "$root" diff --name-status -M "$base...HEAD")"
printf '%s\n' "$diff" | php "$root/scripts/mutation-scope.php" "$@"
