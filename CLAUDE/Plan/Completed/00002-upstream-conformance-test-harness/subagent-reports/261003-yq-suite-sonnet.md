# yq conformance suite report

Pin: mikefarah/yq v4.54.1, 504fc38780cc46be8444ea1b72fb55919fc0bfb0. Source: `pkg/yqlib/doc/operators/*.md` and `pkg/yqlib/doc/usage/*.md` (usage/headers excluded, they are partials).

## Result

- 574 cases in `tests/Conformance/Yq/fixtures/cases.json`, 82 skipped in `skipped.json`. Regeneration is byte-stable.
- `--testsuite yq`: 574 tests, 562 failures, no PHP errors or fatals. 12 pass vacuously because their documented expected stdout is the empty string and the stub front controller prints nothing to stdout. They will stay meaningful once the CLI is real, but note them if "all red" is expected.
- `--testsuite unit` (all unit tests incl. the other agent's): 53 tests, green. 2 PHPUnit deprecations are reported, not from this work.
- PHPStan max per file: exit 0 on all 11 PHP files. Rector and php-cs-fixer ran writable per file (they reformatted: `new X()->m()` syntax, imports, `@internal`); a final stan pass after them was green.

## Skip reasons (82)

| Count | Reason                                                                           |
| ----- | -------------------------------------------------------------------------------- |
| 25    | code block outside a given/then/output group (stray snippets, input-like blocks) |
| 16    | needs environment variables set (CliRunner cannot supply env)                    |
| 16    | documented output is an error message (stderr/exit code), not stdout             |
| 9     | no 'will output' block follows the command                                       |
| 8     | expression loads files from disk (load operators)                                |
| 3     | several input files                                                              |
| 2     | depends on input file name                                                       |
| 2     | not a yq invocation                                                              |
| 1     | expression/output from a file (`--from-file`, split)                             |

In-place `-i` cases are skipped with their own reason when they occur.

## Case shape

`name` ("operators/add.md: Concatenate arrays", duplicates get " #2"), `source`, `heading`, `command` (eval/e/eval-all/ea or null), `flags` (ordered, value flags such as `-o json` kept as two words), `expression` (nullable), `input` (given file content, fed on stdin), `expected` (stdout, trailing newline added). The test builds `['yq', command?, ...flags, expression?]`. Flags written after the expression upstream are moved before it. The documented input file name is dropped from argv.

## Files

- `tests/Support/Yq/`: `ShellWords`, `YqDocExtractor`, `YqCase`, `YqSkip`, `YqExtraction`, `YqFixtureGenerator`.
- `tests/Unit/Support/Yq/`: `ShellWordsTest`, `YqDocExtractorTest`, `YqFixtureGeneratorTest` (written before the classes; red first).
- `scripts/refresh-yq-fixtures.php <yq-clone>`: writes cases.json and skipped.json.
- `tests/Conformance/Yq/YqConformanceTest.php` (final, data provider keyed by case name).
- `tests/Conformance/Yq/fixtures/`: `cases.json`, `skipped.json`, `LICENSE` (copied from the clone), `.gitattributes` (`-diff linguist-generated`), `NOTICE.txt`.

## Deviation: NOTICE.txt, not NOTICE.md

The hooks daemon rule R-MARKDOWN-WRONG-LOCATION blocked writing `tests/Conformance/Yq/fixtures/NOTICE.md`. I did not route around it via Bash; I wrote `NOTICE.txt` (the jq fixtures also use NOTICE.txt). To get a .md, the owner must add the path to `extra_allowed_markdown_paths`; then rename and update the one reference in `YqConformanceTest.php` docblock.

## Notes for the parent

- Scratch helpers left in `untracked/scratch/` (`yq-stan.sh`, `yq-cs.sh`, logs). `qa -t allCS` does not accept `-p`; I used `-t rector` and `-t fixer` per file instead.
- Not covered: acceptance_tests/\*.sh (research doc option 3) and the Go scenario dump; this is the docs-derived primary source only.
