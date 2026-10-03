#!/usr/bin/env bash
# One command for the whole upstream conformance run: the data-driven cases (jq .test files, yq
# documented examples) and the shell-script suites (jq shtest, yq acceptance scripts), each checked
# against its known-gaps.txt. Exits non-zero on any unexpected failure or unexpected pass.
#
# Usage: scripts/conformance.bash [jq|yq|all]
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
suite="${1:-all}"

case "$suite" in
    jq | yq | all) ;;
    *)
        echo "usage: ${0##*/} [jq|yq|all]" >&2
        exit 2
        ;;
esac

status=0

php "$root/scripts/conformance-report.php" "$suite" || status=1
"$root/scripts/conformance-shell.bash" "$suite" || status=1

if ((status == 0)); then
    echo "CONFORMANCE: OK (every failure is a recorded known gap)"
else
    echo "CONFORMANCE: PROBLEMS FOUND (see unexpected failures or passes above)" >&2
fi

exit "$status"
