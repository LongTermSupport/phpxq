#!/usr/bin/env bash
# CPU-time comparison of several ways to run phpxq (checkout, PHAR with different settings, static binary).
# Immune to load from other processes: user+sys milliseconds, the minimum of REPS runs.
#
#   scripts/bench/artefact-compare.bash [--reps N] [--set small|full] LABEL=COMMAND...
#
# COMMAND is a word-split command prefix that is followed by `jq|yq FILTER FILE`, for example
#   checkout='php bin/phpxq'  phar='php dist/phpxq.phar'  nojit='php -d pcre.jit=0 dist/phpxq.phar'
#   binary='dist/phpxq-linux-x86_64'
# Needs the corpora (run scripts/bench/bench.bash once). Prints one row per workload, one column per label.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
c=$root/untracked/bench/corpus
reps=3
set_name=small
while (($# > 0)); do
    case "$1" in
        --reps)
            reps=$2
            shift
            ;;
        --set)
            set_name=$2
            shift
            ;;
        -h | --help)
            awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *) break ;;
    esac
    shift
done
(($# > 0)) || {
    echo "need at least one LABEL=COMMAND" >&2
    exit 2
}

labels=()
commands=()
for spec in "$@"; do
    labels+=("${spec%%=*}")
    commands+=("${spec#*=}")
done

sel='.[] | select(.active) | .name'
grp='group_by(.group) | map({"group": .[0].group, "count": length})'

cpu() { # command-prefix tool filter file
    local prefix=$1 tool=$2 filter=$3 file=$4 best=999999999 t cmd
    read -r -a cmd <<<"$prefix"
    for _ in $(seq "$reps"); do
        TIMEFORMAT='%U %S'
        t=$( { time "${cmd[@]}" "$tool" "$filter" "$file" >/dev/null; } 2>&1 )
        t=$(echo "$t" | awk '{printf "%d", ($1+$2)*1000}')
        if ((t < best)); then best=$t; fi
    done
    echo "$best"
}

row() { # name tool filter file
    printf '%-20s' "$1"
    local i
    for i in "${!commands[@]}"; do
        printf ' %8s' "$(cpu "${commands[$i]}" "$2" "$3" "$4")"
    done
    echo
}

printf '%-20s' 'workload (CPU ms)'
for l in "${labels[@]}"; do printf ' %8s' "$l"; done
echo

row jq:startup jq . "$c/tiny.json"
row jq:identity-small jq . "$c/small.json"
row jq:select-medium jq "$sel" "$c/medium.json"
row jq:group-medium jq "$grp" "$c/medium.json"
row yq:startup yq . "$c/tiny.yaml"
row yq:identity-small yq . "$c/small.yaml"
row yq:identity-medium yq . "$c/medium.yaml"
row yq:group-medium yq "$grp" "$c/medium.yaml"
if [[ "$set_name" == full ]]; then
    agg='map(.score) | unique | length'
    row jq:select-small jq "$sel" "$c/small.json"
    row jq:identity-medium jq . "$c/medium.json"
    row jq:aggregate-medium jq "$agg" "$c/medium.json"
    row jq:identity-large jq . "$c/large.json"
    row jq:select-large jq "$sel" "$c/large.json"
    row jq:aggregate-large jq "$agg" "$c/large.json"
    row jq:group-large jq "$grp" "$c/large.json"
    row jq:wide-keys jq 'keys | length' "$c/wide.json"
    row jq:deep-walk jq '[.. | numbers] | length' "$c/deep.json"
    row yq:select-small yq "$sel" "$c/small.yaml"
    row yq:select-medium yq "$sel" "$c/medium.yaml"
    row yq:aggregate-medium yq "$agg" "$c/medium.yaml"
    row yq:identity-large yq . "$c/large.yaml"
    row yq:select-large yq "$sel" "$c/large.yaml"
    row yq:aggregate-large yq "$agg" "$c/large.yaml"
    row yq:group-large yq "$grp" "$c/large.yaml"
    row yq:wide-keys yq 'keys | length' "$c/wide.yaml"
    row yq:deep-walk yq '[.. | select(tag == "!!int")] | length' "$c/deep.yaml"
fi
