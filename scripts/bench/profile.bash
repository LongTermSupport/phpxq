#!/usr/bin/env bash
# One-command profile of a jq filter over a benchmark corpus (no Xdebug: a sampling profiler, see profile.php).
#
#   scripts/bench/profile.bash <size> <filter> [repetitions] [rows]
#
#   size         small, medium, large, deep or wide (the corpora of the benchmark suite, generated on first use);
#                append .yaml (for example medium.yaml) to profile yq instead of jq
#   filter       the jq program or yq expression, for example '.[] | select(.active) | .name'
#   repetitions  runs of the program inside one process (default 5)
#   rows         rows per table of the report (default 25)
#
# The report lists, in the share of samples, the functions that were the innermost frame (SELF) and those that
# were anywhere on the stack (INCLUSIVE). Needs the pcntl and posix PHP extensions.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
if (($# < 2)); then
    awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "${BASH_SOURCE[0]}" >&2
    exit 2
fi

size=$1
filter=$2
corpus="$root/untracked/bench/corpus"
file="$corpus/$size"
[[ "$size" == *.yaml ]] || file="$file.json"
if [[ ! -f "$file" ]]; then
    mkdir -p "$corpus"
    php "$root/scripts/bench/bench.php" plan --corpus-dir "$corpus" --sizes small,medium,large --tools jq,yq >/dev/null
fi

exec php "$root/scripts/bench/profile.php" "$filter" "$file" "${3:-5}" "${4:-25}"
