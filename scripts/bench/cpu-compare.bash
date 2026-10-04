#!/usr/bin/env bash
# CPU-time comparison of two checkouts of phpxq jq against jq on PATH; immune to load from other processes.
#
#   scripts/bench/cpu-compare.bash BEFORE_CHECKOUT [REPS]
#
# Prints user+sys milliseconds (minimum of REPS, default 3) per benchmark workload for BEFORE_CHECKOUT/bin/phpxq,
# this checkout's bin/phpxq and jq. Needs the corpora (run scripts/bench/bench.bash once to generate them).
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
before=$1/bin/phpxq
reps=${2:-3}
after=$root/bin/phpxq
c=$root/untracked/bench/corpus
sel='.[] | select(.active) | .name'
agg='map(.score) | unique | length'
grp='group_by(.group) | map({"group": .[0].group, "count": length})'

cpu() { # filter file command...
    local f=$1 file=$2
    shift 2
    local best=999999999 t
    for _ in $(seq "$reps"); do
        TIMEFORMAT='%U %S'
        t=$( { time "$@" "$f" "$file" >/dev/null; } 2>&1 )
        t=$(echo "$t" | awk '{printf "%d", ($1+$2)*1000}')
        if ((t < best)); then best=$t; fi
    done
    echo "$best"
}

run() { # name filter file
    printf '%-18s before=%5s after=%5s jq=%5s\n' "$1" \
        "$(cpu "$2" "$3" php "$before" jq)" "$(cpu "$2" "$3" php "$after" jq)" "$(cpu "$2" "$3" jq)"
}

run startup . "$c/tiny.json"
run identity-small . "$c/small.json"
run select-small "$sel" "$c/small.json"
run identity-medium . "$c/medium.json"
run select-medium "$sel" "$c/medium.json"
run aggregate-medium "$agg" "$c/medium.json"
run group-medium "$grp" "$c/medium.json"
run identity-large . "$c/large.json"
run select-large "$sel" "$c/large.json"
run aggregate-large "$agg" "$c/large.json"
run group-large "$grp" "$c/large.json"
run wide-keys 'keys | length' "$c/wide.json"
run deep-walk '[.. | numbers] | length' "$c/deep.json"
