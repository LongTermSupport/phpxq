#!/usr/bin/env bash
# Differential test: run a seeded corpus of jq invocations through the real jq on PATH and through
# bin/phpxq jq, and report every case whose stdout or exit status differs.
#
# Usage: scripts/differential-jq.bash [seed] [outdir]
#
# The reference jq may be older than the targeted 1.8; accepted differences are listed in
# scripts/differential/known-differences.txt (one exact filter per line) and counted separately.
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
seed="${1:-1}"
out="${2:-$root/untracked/scratch/diff}"
mkdir -p "$out"
php "$root/scripts/differential/generate.php" "$seed" "$out" > /dev/null

known="$root/scripts/differential/known-differences.txt"
report="$out/report.txt"
: > "$report"
total=0
differ=0
accepted=0

cases="$out/cases.tsv"
if [[ -n "${DIFF_GREP:-}" ]]; then
    if grep -P -- "$DIFF_GREP" "$out/cases.tsv" > "$out/cases.filtered.tsv"; then
        cases="$out/cases.filtered.tsv"
    else
        echo "DIFFERENTIAL: no case matches DIFF_GREP" >&2
        exit 1
    fi
fi

while IFS=$'\x1f' read -r flags filter file; do
    total=$((total + 1))
    # word splitting of flags and files is intended
    # shellcheck disable=SC2206
    flag_words=($flags)
    # shellcheck disable=SC2206
    file_words=($file)
    args=("${flag_words[@]}")
    if [[ -n "$filter" ]]; then
        args+=("$filter")
    fi
    ref_out="$(timeout 20 jq "${args[@]}" "${file_words[@]}" 2> "$out/ref.err" < /dev/null)"
    ref_rc=$?
    mine_out="$(timeout 60 php "$root/bin/phpxq" jq "${args[@]}" "${file_words[@]}" 2> "$out/mine.err" < /dev/null)"
    mine_rc=$?
    if [[ "$ref_out" == "$mine_out" && "$ref_rc" == "$mine_rc" ]]; then
        if grep -qE '(PHP )?(Warning|Notice|Deprecated|Fatal error|Stack trace)' "$out/mine.err"; then
            printf 'LEAK\t%s\t%s\t%s\n' "$flags" "$filter" "$file" >> "$report"
            differ=$((differ + 1))
        fi
        continue
    fi
    if [[ -f "$known" ]] && grep -qxF -- "$filter" "$known"; then
        accepted=$((accepted + 1))
        continue
    fi
    differ=$((differ + 1))
    {
        printf 'DIFF\t%s\t%s\t%s\n' "$flags" "$filter" "$file"
        printf '  ref  rc=%s out=%s err=%s\n' "$ref_rc" "$(printf '%s' "$ref_out" | cut -c1-300 | tr '\n' '~')" "$(cut -c1-200 "$out/ref.err" | tr '\n' '~')"
        printf '  mine rc=%s out=%s err=%s\n' "$mine_rc" "$(printf '%s' "$mine_out" | cut -c1-300 | tr '\n' '~')" "$(cut -c1-200 "$out/mine.err" | tr '\n' '~')"
    } >> "$report"
done < "$cases"

echo "DIFFERENTIAL: seed=$seed cases=$total differences=$differ accepted=$accepted report=$report"
[[ "$differ" -eq 0 ]]
