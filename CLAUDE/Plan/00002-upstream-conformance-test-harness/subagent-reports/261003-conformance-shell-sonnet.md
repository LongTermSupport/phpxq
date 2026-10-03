# Shell conformance suites (Task 2.3, shell part of 2.5)

## Done

- Vendored unmodified (modes preserved, `.gitattributes` `* -text -diff` beside each tree):
  - `tests/Conformance/Yq/acceptance/*.sh` (17 scripts) and `acceptance/scripts/shunit2`.
  - `tests/Conformance/Jq/shell/tests/{shtest,setup,jq-f-test.sh,no-main-program.jq,yes-main-program.jq,utf8test,torture/}`.
  - `shell/tests/modules` is a relative symlink to `../../modules`.
- NOTICE.md updated for both tools (new files, shunit2 Apache-2.0 attribution, refresh steps). The Apache-2.0 licence
  text is not available locally (the clone ships none, only the header and URL), so only the header attribution
  and URL are recorded and the NOTICE says so.
- `scripts/conformance-shell.bash [jq|yq|all]`: cases `shell:<script>` and `shell:shtest`, wrappers on PATH or in cwd,
  `timeout` (CASE_TIMEOUT, default 300), logs in `var/conformance/<suite>-shell-<case>.log`, shunit2 `Ran N tests`
  and verdict extracted (ANSI stripped), gap enforcement from `$GAPS_DIR/<Jq|Yq>/known-gaps.txt`
  (default `tests/Conformance`), exit 1 on unexpected failure or pass. Works from any cwd.
- `qaConfig/qa.php`: `withIgnoredPaths()` for the two vendored shell directories, with a justification comment.

## Observed (stub)

- With the real blanket gap files: `yq shell: 17 scripts | 0 passed | 17 expected failures | 0 unexpected failures | 0 unexpected passes`,
  `jq shell: 1 script | 0 passed | 1 expected failures | 0 unexpected failures | 0 unexpected passes`, exit 0.
- With no gap file (`GAPS_DIR=/nonexistent`): 17 unexpected failures, exit 1.
- yq example: `basic.sh` reports `Ran 35 tests FAILED (failures=60)`; jq shtest exits 70.

## QA

- `qa -t shellCheck`: green (44 tracked files). Note the lane only scans git-tracked files, so the new script is
  not covered until committed; I ran the vendored shellcheck binary directly on it: clean at default severity.
- `qa -t allStatic`: exit 0, nothing red.
- The lint hook once reported a QA-lock collision (exit 75) on qaConfig/qa.php; that is the concurrent agent, not a defect.
