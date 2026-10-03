# PHP conformance report and known-gap enforcement (Tasks 2.4, 2.5 PHP side)

## Delivered

- `tests/Support/Conformance/`: `ConformanceCase`, `ConformanceSuiteInterface`, `JqConformanceSuite`, `YqConformanceSuite`, `GapEntry`, `GapList` (`fromFile`, `fromLines`, `reasonFor`, `entries`, `withoutGlobPrefix`), `ConformanceReport` (runs a suite), `ConformanceReportResult` (counts, `hasProblems()`, `summaryLines()`).
- Unit tests first in `tests/Unit/Support/Conformance/` (5 files, 28 tests).
- `tests/Conformance/{Jq,Yq}/known-gaps.txt` with the blanket entry (Plan 00003 / 00004).
- `JqConformanceTest` and `YqConformanceTest` are thin (`id => [ConformanceCase]`, `assertNull($case->evaluate(...), $case->id)`), raw view, gap lists ignored.
- `scripts/conformance-report.php [jq|yq|all]`, exit 0 when no problems, else 1; exit 2 on a bad argument.

## Behaviour notes

- yq evaluator now also requires exit code 0. Jq logic is unchanged; value comparison uses sebastian/comparator (same loose equality as `assertEquals`).
- Failure messages: `expected exit code N, got exit code M; stderr: ...`, `output mismatch: ...`, `stdout mismatch: ...`.
- Unexpected failures and passes listed up to 20 in the summary, then `... and N more`; messages collapsed to one line and cut at 200 chars. Stale gap globs print as `WARNING` lines only. Globs starting `shell:` are dropped before classification and stale detection.
- Gap file format: `<glob><TAB><reason>`; the reason may itself contain tabs (split on the first tab only); CRLF tolerated; empty glob or reason throws with the line number.

## Results

- Default unit suite: OK (81 tests).
- `--testsuite jq`: 879 tests, 879 failures, no PHP errors. `--testsuite yq`: 574 tests, 574 failures (the 12 former vacuous passes now fail on exit code).
- `php scripts/conformance-report.php` printed, exit 0:
  - `jq: 879 cases | 0 passed | 879 expected failures (known gaps) | 0 unexpected failures | 0 unexpected passes`
  - `yq: 574 cases | 0 passed | 574 expected failures (known gaps) | 0 unexpected failures | 0 unexpected passes`
  - `TOTAL: 1453 cases | 1453 as expected | OK`
- PHPStan max per file: exit 0 on all 16 new or changed PHP files. Rector and php-cs-fixer ran writable over the project, both exit 0. Full pipeline not run.
