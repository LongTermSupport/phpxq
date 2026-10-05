#!/usr/bin/env bash
# One-command benchmark runner for phpxq.
#
#   scripts/bench/bench.bash run      [options]   measure everything, store JSON + Markdown report
#   scripts/bench/bench.bash baseline [options]   like run, stored as benchmarks/baselines/<label>.json
#   scripts/bench/bench.bash report FILE [--baseline FILE]   re-render a stored result
#
# Options for run/baseline:
#   --label NAME       run label (default: git short hash, or "local")
#   --reps N           timed repetitions per workload (default 7)
#   --warmup N         warm-up runs per workload, the first one probes status (default 2)
#   --sizes a,b        record corpora to include: small,medium,large (default small,medium)
#   --tools jq,yq      tools to benchmark (default jq,yq)
#   --filter TEXT      only workloads whose id contains TEXT
#   --binary PATH      also benchmark a packaged phpxq binary (target ids phpxq-bin-jq / phpxq-bin-yq)
#   --compare FILE     print the report with ratios against a stored baseline
#   --out FILE         result JSON path (default untracked/bench/<label>-<utc time>.json)
#
# Targets: "phpxq-<tool>" runs `php bin/phpxq <tool>`; "jq" / "yq" are the reference tools found on PATH
# (only the real mikefarah yq counts; a missing or different yq is recorded as "unavailable"). Tools phpxq
# has not implemented yet are recorded with status "not-implemented"; nothing here needs them to work.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
bench_php=(php "$root/scripts/bench/bench.php")
# Command words used to run phpxq itself; set BENCH_PHP to benchmark another PHP or ini flags, e.g.
# BENCH_PHP="php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M".
subject_php=${BENCH_PHP:-php}
measure="$root/scripts/bench/measure.bash"

print_usage() {
    awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "${BASH_SOURCE[0]}"
}

command=${1:-run}
if (($# > 0)); then
    shift
fi

if [[ $command == report ]]; then
    exec "${bench_php[@]}" report "$@"
fi
if [[ $command != run && $command != baseline ]]; then
    print_usage >&2
    exit 2
fi

label=""
reps=7
warmup=2
sizes="small,medium"
tools="jq,yq"
filter=""
binary=""
compare=""
out=""
while (($# > 0)); do
    if (($# < 2)); then
        echo "option $1 needs a value" >&2
        exit 2
    fi
    case $1 in
        --label) label=$2 ;;
        --reps) reps=$2 ;;
        --warmup) warmup=$2 ;;
        --sizes) sizes=$2 ;;
        --tools) tools=$2 ;;
        --filter) filter=$2 ;;
        --binary) binary=$2 ;;
        --compare) compare=$2 ;;
        --out) out=$2 ;;
        *)
            echo "unknown option: $1" >&2
            print_usage >&2
            exit 2
            ;;
    esac
    shift 2
done

if [[ -z $label ]]; then
    if ! label=$(git -C "$root" rev-parse --short HEAD 2>/dev/null); then
        label=local
    fi
fi
commit=unknown
if head_commit=$(git -C "$root" rev-parse --short HEAD 2>/dev/null); then
    commit=$head_commit
fi
started=$(date -u +%Y-%m-%dT%H:%M:%S+00:00)
stamp=$(date -u +%Y%m%dT%H%M%SZ)
work="$root/untracked/bench"
corpus="$work/corpus"
mkdir -p "$work" "$corpus"
if [[ -z $out ]]; then
    if [[ $command == baseline ]]; then
        out="$root/benchmarks/baselines/$label.json"
    else
        out="$work/$label-$stamp.json"
    fi
fi

targets_file=$(mktemp "$work/targets.XXXXXX")
raw_file=$(mktemp "$work/raw.XXXXXX")
plan_file=$(mktemp "$work/plan.XXXXXX")
trap 'rm -f "$targets_file" "$raw_file" "$plan_file"' EXIT

# Target discovery. Each line: id, tool, role, version, command (words separated by single spaces).
declare -A target_cmd
declare -A missing
add_target() {
    local id=$1 tool=$2 role=$3 version=$4 cmd=$5
    printf '%s\t%s\t%s\t%s\t%s\n' "$id" "$tool" "$role" "$version" "$cmd" >>"$targets_file"
    target_cmd[$id]=$cmd
}

IFS=, read -r -a tool_list <<<"$tools"
for tool in "${tool_list[@]}"; do
    add_target "phpxq-$tool" "$tool" subject "$commit" "$subject_php $root/bin/phpxq $tool"
    if [[ -n $binary ]]; then
        add_target "phpxq-bin-$tool" "$tool" subject "" "$binary $tool"
    fi
    if path=$(command -v "$tool"); then
        version=$("$path" --version 2>&1 | head -n 1)
        if [[ $tool == yq && $version != *mikefarah* ]]; then
            missing[$tool]="yq on PATH is not mikefarah/yq: $version"
        else
            add_target "$tool" "$tool" reference "$version" "$path"
        fi
    else
        missing[$tool]="$tool not found on PATH"
    fi
done

plan_args=(plan --corpus-dir "$corpus" --sizes "$sizes" --tools "$tools")
if [[ -n $filter ]]; then
    plan_args+=(--filter "$filter")
fi
"${bench_php[@]}" "${plan_args[@]}" >"$plan_file"

echo "phpxq benchmark: label=$label reps=$reps warmup=$warmup sizes=$sizes tools=$tools" >&2
while IFS=$'\t' read -r workload_id tool input batch filter_text; do
    for id in "${!target_cmd[@]}"; do
        target_tool=${id#phpxq-bin-}
        target_tool=${target_tool#phpxq-}
        if [[ $target_tool != "$tool" ]]; then
            continue
        fi
        read -r -a words <<<"${target_cmd[$id]}"
        "$measure" "$id" "$workload_id" "$warmup" "$reps" "$batch" -- "${words[@]}" "$filter_text" "$input" >>"$raw_file"
    done
    if [[ -n ${missing[$tool]:-} ]]; then
        printf '%s\t%s\tunavailable\t0\t\t%s\n' "$tool" "$workload_id" "${missing[$tool]}" >>"$raw_file"
    fi
done <"$plan_file"

# Unavailable references still need a target row so the report can list them.
for tool in "${!missing[@]}"; do
    printf '%s\t%s\treference\t\t\n' "$tool" "$tool" >>"$targets_file"
done

"${bench_php[@]}" record --targets "$targets_file" --raw "$raw_file" --out "$out" --label "$label" --started "$started" \
    --setting "repetitions=$reps" --setting "warmup=$warmup" --setting "sizes=$sizes" --setting "tools=$tools" \
    --setting "filter=${filter:-none}" --setting "binary=${binary:-none}" --setting "commit=$commit" --setting "subject_php=$subject_php"

if [[ -n $compare ]]; then
    "${bench_php[@]}" report "$out" --baseline "$compare"
fi
