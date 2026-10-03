# jq conformance suite report

## Delivered

- tests/Conformance/Jq/fixtures/: seven upstream .test files (byte-identical, verified with cmp at copy time), COPYING (jq MIT), NOTICE.txt (see deviation), .gitattributes (`* -text -diff -whitespace`).
- tests/Conformance/Jq/modules/: full upstream tests/modules tree plus .gitattributes. Passed to every case via `-L`.
- tests/Support/Jq/JqTestCase.php, JqTestFileParser.php (parseFile, parseString).
- tests/Unit/Support/Jq/JqTestFileParserTest.php (10 tests, written first, red before the parser existed) and JqVendoredFixturesTest.php (per-file counts 550/231/47/20/19/10/2, 19 %%FAIL in jq.test, total 879).
- tests/Conformance/Jq/JqConformanceTest.php: one data set per case, keyed `file:line` (program line).

## Case semantics

- Normal: `jq -L modules -c -- PROGRAM` with input plus newline on stdin; asserts exit 0, then assertEquals on json_decode (objects as stdClass, bigint as string) of each expected line vs each stdout line; count must match.
- %%FAIL: `jq -L modules -n -c -- PROGRAM`; asserts exit 3 (jq compile error) and empty stdout. The expected message lines are parsed and kept in JqTestCase but are NOT compared (decision: message text is a later, separate step).
- Exit code 0 is asserted for normal cases, so a no-output case does not pass trivially against the stub. Upstream cases that end in an uncaught runtime error might need this relaxed once phpxq exists.

## Results

- Unit (Jq filter): 19 tests OK.
- `--testsuite jq`: 879 tests, 879 failures (exit 70 'not implemented'), no PHP errors.
- PHPStan max on tests/Support/Jq, tests/Unit/Support/Jq, tests/Conformance/Jq: 0 errors. Rector and php-cs-fixer ran in writable mode on the same paths (they reformatted my files, e.g. adding @internal, `use` imports).

## Deviations and notes

- NOTICE.md was blocked by the daemon rule R-MARKDOWN-WRONG-LOCATION (a .md under tests/ is not allowed). I wrote NOTICE.txt instead. To get NOTICE.md the parent must add the path to `extra_allowed_markdown_paths`.
- NOTICE.txt refresh step 4 refers to the counts in JqVendoredFixturesTest.php.
- allCS does not accept `-p`; I used `-t rector` and `-t fixer` per path. The QA lock was contended by the Yq agent, so runs were retried.
- phpunit.xml (parent-owned) emits 2 PHPUnit deprecations: `executionOrder="depends,random"` and the 13.3 schema URL.
- The BOM at the start of jq.test input line 49 is preserved in the case input.
