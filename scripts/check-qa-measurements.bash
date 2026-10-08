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
# - With PHPXQ_MUTATION_BASE set (CI runs scoped by scripts/mutation-scope.bash), the scope is recomputed
#   from that ref and qaConfig/infection.json must be exactly what that scope writes (absent unless scoped):
#   only a change that maps to no source file may have no summary, and only a scoped run may generate no
#   mutants (its files hold none, e.g. interfaces alone). Without it the run must
#   have been full, so a leftover scoped qaConfig/infection.json fails the check.
#
# The floors sit at the value the unit suite earns today and only ever move up (the cap only down); raising
# them is plan 00011.
# Usage: scripts/check-qa-measurements.bash   (after vendor/bin/qa or vendor/bin/qa -t infection)
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
clover_rel=var/qa/phpunit_logs/coverage.clover
summary_rel=var/qa/infection/summary-log.txt
clover="$root/$clover_rel"
summary="$root/$summary_rel"

# Measured with Xdebug, the CI coverage driver (PCOV counts slightly more statements as covered: 93.36%).
line_floor=92.83
method_floor=81.39
# Percent of generated mutants Infection may skip: measured 13.9% (3811 of 27419, a complete unit-suite run).
max_skipped_percent=14

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

base="${PHPXQ_MUTATION_BASE:-}"
scope=all
if [[ -n "$base" ]]; then
    # Recomputed here rather than trusted from the workflow: only a change that maps to no source may skip.
    # --verify also fails unless qaConfig/infection.json is exactly what --write leaves for that scope, so the
    # mutants counted below are the scope's: a missing or stale override would have mutated something else.
    verify_status=0
    scope_report="$("$root/scripts/mutation-scope.bash" "$base" --verify)" || verify_status=$?
    scope="$(printf '%s\n' "$scope_report" | awk -F= '$1 == "scope" { print $2 }')"
    echo "mutation scope since $base: ${scope:-unknown}"
    if [[ "$verify_status" -ne 0 ]]; then
        fail "the scoped mutation config qaConfig/infection.json does not match the scope since $base (see above)"
        scope=all
    fi
elif [[ -f "$root/qaConfig/infection.json" ]]; then
    fail "qaConfig/infection.json (a scoped mutation config from scripts/mutation-scope.bash) is present, so a full run measured only part of src/; delete it"
fi

if [[ "$scope" == none ]]; then
    echo "mutants: none to check, the change maps to no source file"
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
        if [[ "$total" -eq 0 && "$scope" == files ]]; then
            # Infection ran over the scope (the summary is from this run) and found nothing to mutate in it, as for
            # a change to interfaces alone. That is a pass; a missing summary is not, and fails above.
            echo "mutants: the scope is not empty but no mutants were generated from it, so there is no score to check"
        elif [[ "$total" -eq 0 ]]; then
            fail "Infection generated no mutants from all of src/"
        else
            if [[ $((skipped * 100)) -gt $((total * max_skipped_percent)) ]]; then
                fail "Infection skipped $skipped of $total mutants, more than $max_skipped_percent% (their covering tests are slower than the timeout), and the mutation score leaves them out"
            fi
            report_msi
        fi
    fi
fi

exit "$failed"
