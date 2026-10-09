#!/usr/bin/env bash
# Times one command and prints a single tab-separated measurement line (the format RawResultParser reads):
#   target-id  workload-id  status  output-bytes  samples-ms(comma separated)  note
#
# Usage: scripts/bench/measure.bash TARGET_ID WORKLOAD_ID WARMUP REPS BATCH -- COMMAND [ARGS...]
#
# The first warm-up run doubles as a probe: if it fails, the status is recorded ("not-implemented" when
# stderr says so, otherwise "failed") and no timing is attempted. One sample is the wall-clock time of
# BATCH consecutive invocations, in milliseconds.
set -euo pipefail

target_id=$1
workload_id=$2
warmup=$3
reps=$4
batch=$5
shift 6
command=("$@")

scratch=$(mktemp -d)
trap 'rm -rf "$scratch"' EXIT

emit() {
    local status=$1 bytes=$2 samples=$3 note=$4
    note=$(printf '%s' "$note" | tr '\t\n' '  ')
    printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$target_id" "$workload_id" "$status" "$bytes" "$samples" "$note"
}

run_once() {
    "${command[@]}" >"$scratch/out" 2>"$scratch/err" </dev/null
}

status=0
run_once || status=$?
if ((status != 0)); then
    note=$(head -c 200 "$scratch/err")
    if grep -qi 'not implemented' "$scratch/err"; then
        emit not-implemented 0 '' "$note"
    else
        emit failed 0 '' "exit $status: $note"
    fi
    exit 0
fi
bytes=$(wc -c <"$scratch/out")

for ((i = 1; i < warmup; i++)); do
    if ! run_once; then
        emit failed "$bytes" '' "failed during warm-up run $((i + 1))"
        exit 0
    fi
done

samples=()
for ((r = 0; r < reps; r++)); do
    start=$EPOCHREALTIME
    for ((b = 0; b < batch; b++)); do
        if ! run_once; then
            emit failed "$bytes" '' "failed during repetition $((r + 1))"
            exit 0
        fi
    done
    end=$EPOCHREALTIME
    # EPOCHREALTIME is seconds.microseconds; deleting the dot gives microseconds as an integer.
    start_us=${start/./}
    end_us=${end/./}
    elapsed_us=$((10#$end_us - 10#$start_us))
    samples+=("$(printf '%d.%03d' $((elapsed_us / 1000)) $((elapsed_us % 1000)))")
done

csv=$(IFS=,; echo "${samples[*]}")
emit ok "$bytes" "$csv" ''
