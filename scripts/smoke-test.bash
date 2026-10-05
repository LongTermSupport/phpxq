#!/usr/bin/env bash
# Smoke-tests a built phpxq artefact (the PHAR or a static binary) exactly as a user would run it.
#
# Usage: scripts/smoke-test.bash [--static] [--expect-version X.Y.Z] <artefact>
#   --static                 the artefact must run with an empty environment (no PATH, no php.ini) and,
#                            on Linux, must not be a dynamically linked executable
#   --expect-version X.Y.Z   the version it must report (default: the VERSION file)
#
# Checks: `--version` reports the release; no arguments is a usage error; the busybox-style names `jq`
# and `yq` dispatch to their tool; stdin is read, results and exit codes match jq/yq; no PHP error ever
# reaches the user.
set -euo pipefail

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/packaging.bash
source "$(dirname "${BASH_SOURCE[0]}")/lib/packaging.bash"

static=0
expect_version=""
artefact=""
while (($# > 0)); do
    case "$1" in
        --static) static=1 ;;
        --expect-version)
            [[ -n "${2:-}" ]] || die "--expect-version needs a value"
            expect_version="$2"
            shift
            ;;
        -h | --help)
            awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        -*) die "unknown argument: $1" ;;
        *) artefact="$1" ;;
    esac
    shift
done

[[ -n "$artefact" ]] || die "usage: smoke-test.bash [--static] [--expect-version X.Y.Z] <artefact>"
[[ -f "$artefact" ]] || die "artefact not found: $artefact"
artefact="$(cd "$(dirname "$artefact")" && pwd)/$(basename "$artefact")"
[[ -x "$artefact" ]] || die "artefact is not executable: $artefact"
[[ -n "$expect_version" ]] || expect_version="$(release_version)"

work="$(mktemp -d "${TMPDIR:-/tmp}/phpxq-smoke.XXXXXX")"
trap 'rm -rf "$work"' EXIT
ln -s "$artefact" "$work/jq"
ln -s "$artefact" "$work/yq"

failures=0
fail() {
    echo "  FAIL  $*" >&2
    failures=$((failures + 1))
}
pass() { echo "  ok    $*"; }

# run_case <program> <stdin> <args...>: sets rc, out, err. Static artefacts get an empty environment.
run_case() {
    local program="$1" input="$2"
    shift 2
    rc=0
    if ((static)); then
        env -i "$program" "$@" <<<"$input" >"$work/out" 2>"$work/err" || rc=$?
    else
        "$program" "$@" <<<"$input" >"$work/out" 2>"$work/err" || rc=$?
    fi
    out="$(cat "$work/out")"
    err="$(cat "$work/err")"
}

no_php_error() {
    if [[ "$out$err" == *"Fatal error"* || "$out$err" == *"PHP Warning"* || "$out$err" == *"Parse error"* || "$out$err" == *"Stack trace"* ]]; then
        fail "$1: a PHP error reached the user: $err"
        return 1
    fi
}

echo "Smoke-testing $artefact (expecting version $expect_version)"

run_case "$artefact" "" --version
if ((rc == 0)) && [[ "${out%%$'\n'*}" == "phpxq $expect_version" ]]; then pass "--version starts with 'phpxq $expect_version'"; else fail "--version: rc=$rc out='$out' err='$err'"; fi

run_case "$artefact" ""
if ((rc == 2)) && [[ "$err" == *usage* ]]; then pass "no arguments is a usage error (exit 2)"; else fail "no arguments: rc=$rc err='$err'"; fi

# expect_case <label> <expected-rc> <expected-stdout> <program> <stdin> <args...>
expect_case() {
    local label="$1" want_rc="$2" want_out="$3"
    shift 3
    run_case "$@"
    if no_php_error "$label"; then
        if ((rc == want_rc)) && [[ "$out" == "$want_out" ]]; then
            pass "$label"
        else fail "$label: rc=$rc (want $want_rc) out='$out' (want '$want_out') err='$err'"; fi
    fi
}

expect_case "phpxq jq .a reads stdin" 0 "1" "$artefact" '{"a":1}' jq .a
expect_case "phpxq yq .a reads stdin" 0 "1" "$artefact" 'a: 1' yq .a
expect_case "phpxq jq -c over a pipeline" 0 '[2,4]' "$artefact" '[1,2]' jq -c 'map(. * 2)'
expect_case "phpxq yq converts YAML to JSON" 0 '{"a":[1,2]}' "$artefact" $'a:\n  - 1\n  - 2' yq -o=json -I=0 .
expect_case "jq -e exits 1 on a null result" 1 "null" "$artefact" 'null' jq -e .
expect_case "jq exits 3 on a program that does not compile" 3 "" "$artefact" '{}' jq '.['

for tool in jq yq; do
    expect_case "a program named '$tool' dispatches to $tool (busybox style)" 0 "1" "$work/$tool" '{"a":1}' .a
done

run_case "$work/jq" "" --version
if ((rc == 0)) && [[ "$out" == jq-* ]]; then pass "jq --version prints the jq-compatible version"; else fail "jq --version: rc=$rc out='$out'"; fi
run_case "$work/yq" "" --version
if ((rc == 0)) && [[ "$out" == yq\ * ]]; then pass "yq --version prints the yq-compatible version"; else fail "yq --version: rc=$rc out='$out'"; fi

if ((static)) && [[ "$(uname -s)" == Linux ]] && command -v ldd >/dev/null; then
    # ldd exits non-zero for a static executable, so its output is captured rather than piped.
    ldd_rc=0
    ldd_output="$(ldd "$artefact" 2>&1)" || ldd_rc=$?
    if [[ "$ldd_output" == *"not a dynamic executable"* || "$ldd_output" == *"statically linked"* ]]; then
        pass "statically linked"
    else fail "binary is dynamically linked (ldd rc=$ldd_rc): $ldd_output"; fi
fi

if ((failures > 0)); then
    die "$failures smoke check(s) failed for $artefact"
fi
echo "Smoke test passed: $artefact"
