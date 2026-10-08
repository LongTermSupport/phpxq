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
#   and reports no skipped mutants.
#
# The floors sit at the value the unit suite earns today and only ever move up.
# Usage: scripts/check-qa-measurements.bash   (after vendor/bin/qa, or vendor/bin/qa -t allTests)
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
clover_rel=var/qa/phpunit_logs/coverage.clover
summary_rel=var/qa/infection/summary-log.txt
clover="$root/$clover_rel"
summary="$root/$summary_rel"

line_floor=93.36
method_floor=81.71

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

if [[ ! -f "$summary" ]]; then
    fail "no Infection summary at $summary_rel: mutation testing did not run (php-qa-ci skips it without Xdebug)"
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
            fail "Infection generated no mutants"
        fi
        if [[ "$skipped" -ne 0 ]]; then
            fail "Infection skipped $skipped of $total mutants (their covering tests are slower than the timeout), so the mutation score leaves them out"
        fi
    fi
fi

exit "$failed"
