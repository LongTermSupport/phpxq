#!/usr/bin/env bash
# Fails unless the last `vendor/bin/qa` run really measured coverage and mutation, and both meet their floors.
#
# php-qa-ci skips its Infection lane when Xdebug is missing and does not fail the run for it, and Infection
# itself leaves a mutant out of the score (as "Skipped") when the tests covering it are slower than its
# timeout. Either way the mutation floor in qaConfig/qa.php would pass while measuring little or nothing.
# This check makes both visible, and adds the line and method coverage floors php-qa-ci does not have:
#
# - var/qa/phpunit_logs/coverage.clover exists, and its statement and method coverage meet the floors below;
# - var/qa/infection/summary-log.txt exists, is newer than the coverage report (so it is from this run),
#   and reports no more skipped mutants than the cap below; the mutation score is printed.
# - php-qa-ci mutates only what a branch changed (automatic diff mode) and says which scope ran on the first
#   `Infection:` line of its output, which CI keeps in the file named by PHPXQ_QA_LOG. A run that was FULL is
#   accepted only for the two reasons upstream gives that are not a fault of the clone: the change touches
#   configuration every mutant depends on, or the branch is the default one. Any other full run (a shallow
#   clone with no merge base, no known default branch, a detached HEAD) fails: it would take hours and
#   measure what the branch did not change. A diff run that has nothing to mutate has no summary and passes;
#   one whose files hold no mutable code (interfaces alone) has a summary of zero mutants and passes.
#
# The floors sit at the value the unit suite earns today and only ever move up (the cap only down); raising
# them is plan 00011. Infection holds a diff run to its own floor (qaConfig/qa.php).
# Usage: PHPXQ_QA_LOG=<file holding the output of vendor/bin/qa> scripts/check-qa-measurements.bash
#   Without PHPXQ_QA_LOG the scope is not checked (a local run), only the summary.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
clover_rel=var/qa/phpunit_logs/coverage.clover
summary_rel=var/qa/infection/summary-log.txt
clover="$root/$clover_rel"
summary="$root/$summary_rel"

# Measured with Xdebug, the CI coverage driver (PCOV counts slightly more statements as covered: 93.36%).
line_floor=92.83
method_floor=81.39
# Percent of generated mutants Infection may skip. A mutant is skipped when the tests covering it run longer than
# Infection's timeout, so the count depends on the machine: 13.9% (3811 of 27419) on the development host and 15.3%
# (4195 of 27369) on the GitHub runner. The cap guards against the hollow state this check exists to catch (70%
# skipped when the conformance suite counted as coverage), not against runner speed; plan 00011 lowers it by making
# the slow tests faster.
max_skipped_percent=20

failed=0
fail() {
    echo "::error title=QA measurement::$*"
    failed=1
}

# check_floor <label> <covered> <total> <floor percent>: percentages compared in hundredths, integers only.
check_floor() {
    local label="$1" covered="$2" total="$3" floor="$4" measured floor_hundredths shown
    if [[ "$total" -eq 0 ]]; then
        fail "$label coverage: the report counts no $label"
        return
    fi
    measured=$((covered * 10000 / total))
    floor_hundredths="$(awk -v f="$floor" 'BEGIN { printf "%d", f * 100 + 0.5 }')"
    shown="$(printf '%d.%02d' $((measured / 100)) $((measured % 100)))"
    echo "$label coverage: $covered/$total = $shown% (floor $floor%)"
    if [[ "$measured" -lt "$floor_hundredths" ]]; then
        fail "$label coverage $shown% is below the floor of $floor%"
    fi
}

# report_msi: prints the mutation score Infection computed from the summary counts, so a run can be ratcheted
# (Infection itself enforces the floors in qaConfig/qa.php). Detected = killed + timed out + errored + syntax
# errors; MSI is over every mutant it did not skip or ignore, covered MSI leaves out the uncovered ones too.
report_msi() {
    awk -F': *' '
        { count[$1] = $2 }
        END {
            detected = count["Killed by Test Framework"] + count["Killed by Static Analysis"] + count["Timed Out"] + count["Errored"] + count["Syntax Errors"]
            measured = count["Total"] - count["Skipped"] - count["Ignored"]
            covered = measured - count["Not Covered"]
            if (measured > 0) printf "mutation score: MSI %.2f%%", 100 * detected / measured
            if (covered > 0) printf ", covered MSI %.2f%%", 100 * detected / covered
            printf " (%d detected of %d measured, %d not covered)\n", detected, measured, count["Not Covered"]
        }
    ' "$summary"
}

if [[ ! -f "$clover" ]]; then
    fail "no coverage report at $clover_rel: the phpunit lane ran without a coverage driver (Xdebug or PCOV)"
else
    # The project-wide totals are the one <metrics> element with a `files` attribute (per-file ones have none).
    metrics="$(awk '
        function attribute(name,    found) {
            if (match($0, " " name "=\"[0-9]+\"")) {
                found = substr($0, RSTART, RLENGTH)
                gsub(/[^0-9]/, "", found)
                return found
            }
            return "missing"
        }
        /<metrics files="/ {
            print attribute("statements"), attribute("coveredstatements"), attribute("methods"), attribute("coveredmethods")
            exit
        }
    ' "$clover")"
    read -r statements covered_statements methods covered_methods <<<"${metrics:-missing missing missing missing}"
    if [[ ! "$statements $covered_statements $methods $covered_methods" =~ ^[0-9]+\ [0-9]+\ [0-9]+\ [0-9]+$ ]]; then
        fail "$clover_rel has no readable project-wide metrics"
        statements=0 covered_statements=0 methods=0 covered_methods=0
    fi
    check_floor statements "$covered_statements" "$statements" "$line_floor"
    check_floor methods "$covered_methods" "$methods" "$method_floor"
fi

# check_scope: reads the `Infection:` lines php-qa-ci printed and fails on a run that was full for a reason
# that is a fault of the clone rather than of the change. Sets nothing_to_mutate=1 when the diff held no source.
nothing_to_mutate=0
check_scope() {
    local log="${PHPXQ_QA_LOG:-}" scope_line full_runs
    if [[ -z "$log" ]]; then
        echo "mutation scope: not checked (PHPXQ_QA_LOG is unset)"
        return
    fi
    if [[ ! -f "$log" ]]; then
        fail "PHPXQ_QA_LOG names $log, which does not exist: the output of vendor/bin/qa was not kept"
        return
    fi
    if ! scope_line="$(grep -m1 -E 'Infection: (auto diff mode|diff mode|full run)' "$log")"; then
        fail "$log has no Infection scope line: the Infection lane did not run (php-qa-ci skips it without Xdebug)"
        return
    fi
    echo "mutation scope: $scope_line"
    if full_runs="$(grep -E 'Infection: full run' "$log")" \
        && printf '%s\n' "$full_runs" | grep -q -v -E 'does not apply: on the default branch|touches configuration every mutant depends on'; then
        fail "Infection mutated all of src/ for a reason other than a configuration change or the default branch (a shallow clone, no default branch, a detached HEAD, or a forced full run): $full_runs"
    fi
    if grep -q -E 'there are no new mutants to check\. SKIPPING' "$log"; then
        nothing_to_mutate=1
    fi
}
check_scope

if [[ "$nothing_to_mutate" -eq 1 ]]; then
    echo "mutants: none to check, the change holds no source file or test named after one"
elif [[ ! -f "$summary" ]]; then
    fail "no Infection summary at $summary_rel: mutation testing did not run (php-qa-ci skips it without Xdebug) or did not finish"
elif [[ -f "$clover" && "$summary" -ot "$clover" ]]; then
    fail "the Infection summary is older than the coverage report: mutation testing did not run in this QA run"
else
    skipped="$(awk -F': *' '$1 == "Skipped" { print $2 }' "$summary")"
    total="$(awk -F': *' '$1 == "Total" { print $2 }' "$summary")"
    if [[ -z "$skipped" || -z "$total" ]]; then
        fail "the Infection summary has no Total or Skipped line"
    else
        echo "mutants: $total generated, $skipped skipped"
        if [[ "$total" -eq 0 ]]; then
            # Infection ran over the diff (the summary is from this run) and found nothing to mutate in it, as for
            # a change to interfaces alone. That is a pass; a missing summary is not, and fails above.
            echo "mutants: none were generated from what the change touches, so there is no score to check"
        else
            if [[ $((skipped * 100)) -gt $((total * max_skipped_percent)) ]]; then
                fail "Infection skipped $skipped of $total mutants, more than $max_skipped_percent% (their covering tests are slower than the timeout), and the mutation score leaves them out"
            fi
            report_msi
        fi
    fi
fi

exit "$failed"
