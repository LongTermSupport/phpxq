# mikefarah/yq upstream test research

Clone: /workspace/untracked/scratch/upstream/yq (shallow, tag v4.54.1).

## 1. Pin candidate

- Latest stable: v4.54.1 (published 2026-09-29, not prerelease). Previous: v4.53.6.
- Commit SHA: 504fc38780cc46be8444ea1b72fb55919fc0bfb0

## 2. acceptance_tests/\*.sh

- 17 files, 2964 lines, ~190 `testXxx()` functions (basic 35, leading-separator 26, output-format 21, inputs-format 16, inputs-format-auto 13, pipe 12, split-printer 9, empty 8, nul-separator 8, pretty-print 7, bad_args 6, front-matter 4, load-file 4, flags 2, header-processing-off 2, completion 1, shebang 1).
- Format: shunit2 bash functions (scripts/shunit2). Each writes fixture files via heredoc (`cat >test.yml <<EOL`), computes `expected` via `read -r -d '' expected << EOM`, runs `X=$(./yq ARGS file)` and `assertEquals "$expected" "$X"`. ~286 `./yq` invocations, many functions with several asserts (e.g. `e` and `ea` variants). Runner: scripts/acceptance.sh loops over the files.
- Mechanical extraction is poor: heredocs, shell variables, pipes, stderr and exit-code asserts, setUp cleanup. Better handled by hand-curation or by running the scripts with a `./yq` shim pointing at the PHP binary (shunit2 needs bash), i.e. one PHPUnit case per .sh file/function that shells out.

## 3. pkg/yqlib scenarios

- 105 *_test.go files (about 43 operator_*\_test.go, 64 files reference scenarios). Struct is `expressionScenario` (operators_test.go:20), not expressionScenarioTest:
  description, subdescription, explanation []string, environmentVariables map[string]string, document, document2, expression, expected []string, skipDoc bool, expectedError string, dontFormatInputForDoc bool, requiresFormat string, skipForGoccy bool, yqFlags string.
- Counts: 1233 `expression:` entries (about 1444 `description:` lines incl. other structs). 854 are `skipDoc: true`. About 66 have `expectedError`, 30 use `document2` (multi-doc / eval-all).
- Key problem: `expected` is NOT CLI output. It is yq's internal debug form, `"D<docIdx>, P[path], (!!tag)::<yaml>\n"`. Tests call the library directly, not the CLI. Also other test types exist (decoder/encoder tests, format-specific files for xml/csv/toml/hcl/properties, etc.) with different structs.
- Samples:
  1. `{skipDoc: true, expression: "foo" + "bar", expected: ["D0, P[], (!!str)::foobar\n"]}`
  2. `{description: "Concatenate arrays", document: "{a: [1,2], b: [3,4]}", expression: ".a + .b", expected: ["D0, P[a], (!!seq)::[1, 2, 3, 4]\n"]}`
  3. `{description: "Concatenate to existing array", subdescription: "Note that the styling of `a` is kept.", document: "a: [1,2]\nb:\n  - 3\n  - 4", dontFormatInputForDoc: true, expression: ".a += .b", expected: ["D0, P[], (!!map)::a: [1, 2, 3, 4]\nb:\n    - 3\n    - 4\n"]}`
- Extractability: the Go literals are regular (key: value, backtick raw strings and double-quoted strings with Go escapes, `[]string{...}` with trailing `// comments`). A regex/tokeniser is feasible but fragile (string concatenation with `+`, constants, helper funcs, `fmt.Sprintf`). Most robust is a one-off Go program in the yq module that imports the scenario slices (same package `yqlib`, so as a temporary `_test.go` file) and dumps JSON. That gives exact values, but the output is still internal-format expected values.
- Better mechanical source for CLI-level data: pkg/yqlib/doc/operators/\*.md (66 files, 529 `##` sections, the non-skipDoc scenarios, about 379 after skipDoc filtering) plus doc/usage (14 files). These are generated from the scenarios and give CLI form. Each section: `## description`, optional subdescription, "Given a sample.yml file of:" yaml block, `then` bash block `yq '<expr>' sample.yml` (flags such as `-i`, `-n`, `-o=json` appear in the command line), `will output` yaml block. Parsable with a ~40 line PHP markdown scanner. Only covers documented scenarios (the 854 skipDoc ones are lost); no expected-error cases.

## 4. Licence

- MIT, Copyright (c) 2017 Mike Farah. Vendoring extracted fixtures (data derived from the tests/docs) is permitted provided the copyright notice and MIT permission text are kept (ship a LICENSE/NOTICE next to the fixtures and record tag + SHA in a header). Fetch-on-demand is also fine but makes tests network-dependent and unpinned. Recommendation: vendor with attribution.

## 5. Recommendation (least effort, robust)

1. Pin v4.54.1 / 504fc38 in a small script (e.g. bin/ or tests/fixtures/yq/README with SHA).
2. Primary: PHP (or shell) generator parsing pkg/yqlib/doc/operators/*.md and doc/usage/*.md into one JSON per file (or one big file) of `{name, args (parsed shell words), flags, input, expected_stdout, source}`. About 380-450 cases, a few hundred KB at most. PHPUnit `#[DataProvider]` reads the JSON and invokes the CLI (`bin/yq` args with input via file or stdin), comparing stdout. All red until implemented.
3. Secondary: acceptance_tests, convert by hand-curated or script-assisted extraction of the simple `X=$(./yq ARGS file); assertEquals` cases (probably 60-70% are extractable; the rest need a shell-runner test that executes the original .sh with a `./yq` wrapper). Roughly 190 functions.
4. Optional later: Go one-off dump of the 1233 library scenarios to JSON; the internal `D0, P[..], (!!tag)::` expected strings could be post-processed to the CLI-comparable body (strip prefix), but they differ from CLI formatting (indent of 4, etc.), so treat as phase 2.
5. Total size estimate: docs-derived JSON ~0.3-0.5 MB; full Go test sources are 638 KB; acceptance tests 2964 lines.

## kislyuk/yq note

The project README.md says "[yq](https://github.com/mikefarah/yq)" and that it follows "the jq / yq paradigm ... no new query language", with jq as a separate target. phpxq already provides jq, so a yq that wraps jq syntax (kislyuk, Python) would be redundant; kislyuk/yq's own test suite is a single Python unittest file (test/test.py) with far fewer cases. Evidence strongly favours mikefarah/yq; no decision made here.
