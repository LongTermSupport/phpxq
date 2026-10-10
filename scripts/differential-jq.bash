#!/usr/bin/env bash
# Differential test: run a seeded corpus of jq invocations through the real jq on PATH and through
# bin/phpxq jq, and report every case whose stdout or exit status differs.
#
# Usage: scripts/differential-jq.bash [seed] [outdir]
#   DIFF_GREP=<perl regex>  only run the cases whose line matches
#   DIFF_JOBS=<n>           parallel workers (default: number of CPUs, at most 8)
#
# The reference jq may be older than the targeted 1.8; accepted differences are listed in
# scripts/differential/known-differences.txt: a line is an exact filter, `flags:<flags>` (every case
# with exactly those flags) or `file:<basename>` (every case reading that first file).
# No -e: a differing case is a result to collect, not a reason to stop; failing steps check their own status.
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
seed="${1:-1}"
out="${2:-$root/untracked/scratch/diff}"
mkdir -p "$out"
rm -rf "$out/results" "$out/cases.tsv" "$out/cases.filtered.tsv"
mkdir -p "$out/results"
# Without -e a failed generator would leave the run to an old cases.tsv (or none), so its status is checked.
if ! php "$root/scripts/differential/generate.php" "$seed" "$out" > /dev/null; then
    echo "DIFFERENTIAL: the case generator failed for seed $seed" >&2
    exit 1
fi

known="$root/scripts/differential/known-differences.txt"
report="$out/report.txt"
jobs="${DIFF_JOBS:-$(nproc)}"
if [[ "$jobs" -gt 8 ]]; then
    jobs=8
fi

cases="$out/cases.tsv"
if [[ -n "${DIFF_GREP:-}" ]]; then
    if grep -P -- "$DIFF_GREP" "$out/cases.tsv" > "$out/cases.filtered.tsv"; then
        cases="$out/cases.filtered.tsv"
    else
        echo "DIFFERENTIAL: no case matches DIFF_GREP" >&2
        exit 1
    fi
fi

# Runs one numbered case ("N<US>flags<US>filter<US>file") and leaves results/N.{ok,diff,accepted,leak}.
run_case() {
    local n flags filter file
    IFS=$'\x1f' read -r n flags filter file <<< "$1"
    local work="$out/results/$n.work"
    mkdir -p "$work"
    local -a flag_words file_words args
    # word splitting of flags and files is intended
    # shellcheck disable=SC2206
    flag_words=($flags)
    # shellcheck disable=SC2206
    file_words=($file)
    args=("${flag_words[@]}")
    if [[ -n "$filter" ]]; then
        args+=("$filter")
    fi
    timeout 20 jq "${args[@]}" "${file_words[@]}" > "$work/ref.out" 2> "$work/ref.err" < /dev/null
    local ref_rc=$?
    timeout 60 php "$root/bin/phpxq" jq "${args[@]}" "${file_words[@]}" > "$work/mine.out" 2> "$work/mine.err" < /dev/null
    local mine_rc=$?
    local result="$out/results/$n"
    # jq 1.6 exits 4 on malformed input and prints "parse error:"; 1.8 exits 5 and prints "jq: parse error:"
    if [[ "$ref_rc" == 4 && "$mine_rc" == 5 ]] && grep -q '^parse error:' "$work/ref.err" && grep -q '^jq: parse error:' "$work/mine.err"; then
        mine_rc=4
    fi
    if cmp -s "$work/ref.out" "$work/mine.out" && [[ "$ref_rc" == "$mine_rc" ]]; then
        if grep -qE '(PHP )?(Warning|Notice|Deprecated|Fatal error|Stack trace)' "$work/mine.err"; then
            printf 'LEAK\t%s\t%s\t%s\n' "$flags" "$filter" "$file" > "$result.leak"
        else
            : > "$result.ok"
        fi
    elif [[ -f "$known" ]] && {
        { [[ -n "$filter" ]] && grep -qxF -- "$filter" "$known"; } \
            || grep -qxF -- "flags:$flags" "$known" \
            || grep -qxF -- "file:$(basename "${file_words[0]:-none}")" "$known"
    }; then
        : > "$result.accepted"
    else
        {
            printf 'DIFF\t%s\t%s\t%s\n' "$flags" "$filter" "$file"
            printf '  ref  rc=%s out=%s err=%s\n' "$ref_rc" "$(tr -d '\0' < "$work/ref.out" | cut -c1-300 | tr '\n' '~')" "$(cut -c1-200 "$work/ref.err" | tr '\n' '~')"
            printf '  mine rc=%s out=%s err=%s\n' "$mine_rc" "$(tr -d '\0' < "$work/mine.out" | cut -c1-300 | tr '\n' '~')" "$(cut -c1-200 "$work/mine.err" | tr '\n' '~')"
        } > "$result.diff"
    fi
    rm -rf "$work"
}
export -f run_case
export root out known

total="$(wc -l < "$cases")"
# the single-quoted $1 is expanded by the child shell
# shellcheck disable=SC2016
awk -v US=$'\x1f' '{ print NR US $0 }' "$cases" | tr '\n' '\0' | xargs -0 -P "$jobs" -I{} bash -c 'run_case "$1"' _ {}

: > "$report"
differ=0
accepted=0
for f in "$out"/results/*; do
    case "$f" in
        *.accepted) accepted=$((accepted + 1)) ;;
        *.diff | *.leak)
            differ=$((differ + 1))
            cat "$f" >> "$report"
            ;;
        *) ;;
    esac
done

echo "DIFFERENTIAL: seed=$seed cases=$total differences=$differ accepted=$accepted report=$report"
[[ "$differ" -eq 0 ]]
