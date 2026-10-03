#!/usr/bin/env bash
# Runs the vendored upstream shell-script suites against bin/phpxq: the yq acceptance scripts (one
# case each, id "shell:<script>") and the jq shtest (one case, id "shell:shtest"). Each case is
# checked against tests/Conformance/<Tool>/known-gaps.txt: a failure with no matching gap entry is an
# UNEXPECTED FAILURE, a pass matching a gap entry is an UNEXPECTED PASS; either exits 1.
#
# Usage: scripts/conformance-shell.bash [jq|yq|all]
# Env:   GAPS_DIR       directory holding Jq/known-gaps.txt and Yq/known-gaps.txt
#                       (default tests/Conformance)
#        CASE_TIMEOUT   per-case timeout in seconds (default 300)
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
suite="${1:-all}"
gaps_dir="${GAPS_DIR:-$root/tests/Conformance}"
case_timeout="${CASE_TIMEOUT:-300}"
log_dir="$root/var/conformance"
phpxq="$root/bin/phpxq"
# PHPXQ_BINARY=/path/to/artefact runs the suites against a packaged build (a static binary, or the
# PHAR via `php`) instead of bin/phpxq. The artefact takes the tool name as its first argument.
phpxq_command="php $(printf '%q' "$phpxq")"
if [[ -n "${PHPXQ_BINARY:-}" ]]; then
    [[ -x "$PHPXQ_BINARY" ]] || {
        echo "PHPXQ_BINARY is not an executable file: $PHPXQ_BINARY" >&2
        exit 2
    }
    phpxq_command="$(printf '%q' "$PHPXQ_BINARY")"
fi

if [[ "$suite" != jq && "$suite" != yq && "$suite" != all ]]; then
    echo "usage: ${0##*/} [jq|yq|all]" >&2
    exit 2
fi

mkdir -p "$log_dir"
tmp_root="$(mktemp -d)"
trap 'rm -rf "$tmp_root"' EXIT

passed=0
expected_failures=0
unexpected_failures=0
unexpected_passes=0
exit_status=0

# gap_reason <gaps-file> <case-id>: prints the reason of the first matching entry, returns 1 if none.
gap_reason() {
    local file="$1" id="$2" glob reason
    [[ -f "$file" ]] || return 1
    while IFS=$'\t' read -r glob reason || [[ -n "$glob" ]]; do
        [[ -z "$glob" || "$glob" == '#'* ]] && continue
        # Entries for non-shell cases (the PHP suites) are not ours.
        [[ "$glob" == shell:* || "$glob" == '*'* ]] || continue
        # shellcheck disable=SC2053 # the unquoted right side is the pattern, on purpose
        if [[ "$id" == $glob ]]; then
            printf '%s\n' "$reason"
            return 0
        fi
    done <"$file"
    return 1
}

# shunit_stats <log>: prints "Ran N tests, <verdict>" when the log is a shunit2 log, else nothing.
shunit_stats() {
    awk '
        { gsub(/\033\[[0-9;]*m/, "") }
        /^Ran [0-9]+ tests?\./ { ran = $0; sub(/\.$/, "", ran) }
        /^(OK|FAILED)/ { verdict = $0 }
        END { if (ran != "") printf "%s %s", ran, verdict }
    ' "$1"
}

# record_case <suite-dir> <case-id> <log> <exit-code>: classifies the result and updates counters.
record_case() {
    local name="$1" id="$2" log="$3" rc="$4" reason stats
    stats="$(shunit_stats "$log")"
    if [[ "$rc" -eq 0 ]]; then
        if reason="$(gap_reason "$gaps_dir/$name/known-gaps.txt" "$id")"; then
            unexpected_passes=$((unexpected_passes + 1))
            exit_status=1
            echo "  UNEXPECTED PASS    $id (known gap: $reason) ${stats:+[$stats]}"
        else
            passed=$((passed + 1))
            echo "  pass               $id ${stats:+[$stats]}"
        fi
    elif reason="$(gap_reason "$gaps_dir/$name/known-gaps.txt" "$id")"; then
        expected_failures=$((expected_failures + 1))
        echo "  expected failure   $id (rc=$rc, $reason) ${stats:+[$stats]}"
    else
        unexpected_failures=$((unexpected_failures + 1))
        exit_status=1
        echo "  UNEXPECTED FAILURE $id (rc=$rc, log: $log) ${stats:+[$stats]}"
    fi
}

run_yq_case() {
    local script="$1" work log rc=0
    log="$log_dir/yq-shell-$script.log"
    work="$tmp_root/yq-$script"
    mkdir -p "$work/scripts"
    cp "$root/tests/Conformance/Yq/acceptance/$script" "$work/$script"
    cp "$root/tests/Conformance/Yq/acceptance/scripts/shunit2" "$work/scripts/shunit2"
    printf '#!/bin/sh\nexec %s yq "$@"\n' "$phpxq_command" >"$work/yq"
    chmod 755 "$work/yq" "$work/$script" "$work/scripts/shunit2"
    (cd "$work" && timeout "$case_timeout" "./$script") >"$log" 2>&1 || rc=$?
    record_case Yq "shell:$script" "$log" "$rc"
}

run_jq_case() {
    local work log rc=0
    log="$log_dir/jq-shell-shtest.log"
    work="$tmp_root/jq-shtest"
    mkdir -p "$work/bin"
    printf '#!/bin/sh\nexec %s jq "$@"\n' "$phpxq_command" >"$work/bin/jq"
    chmod 755 "$work/bin/jq"
    (
        cd "$work"
        PATH="$work/bin:$PATH" JQ=jq \
            timeout "$case_timeout" "$root/tests/Conformance/Jq/shell/tests/shtest"
    ) >"$log" 2>&1 || rc=$?
    record_case Jq "shell:shtest" "$log" "$rc"
}

print_summary() {
    echo "$1 shell: $2 $3 | $passed passed | $expected_failures expected failures (known gaps) | $unexpected_failures unexpected failures | $unexpected_passes unexpected passes"
}

reset_counters() {
    passed=0
    expected_failures=0
    unexpected_failures=0
    unexpected_passes=0
}

if [[ "$suite" == yq || "$suite" == all ]]; then
    reset_counters
    total=0
    for path in "$root"/tests/Conformance/Yq/acceptance/*.sh; do
        run_yq_case "$(basename "$path")"
        total=$((total + 1))
    done
    print_summary yq "$total" scripts
fi

if [[ "$suite" == jq || "$suite" == all ]]; then
    reset_counters
    run_jq_case
    print_summary jq 1 script
fi

exit "$exit_status"
