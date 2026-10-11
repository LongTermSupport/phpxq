# Full Code Review: phpxq

**Reviewer model:** Claude Opus 5.5 (`claude-opus-5-5`), coordinating three read-only Opus sub-reviewers:

- the jq engine (`src/Jq`, `src/Cli`, `bin/phpxq`)
- yq, YAML and JSON (`src/Yq`, `src/Yaml`, `src/Json`)
- release tooling (`install.sh`, `scripts/`, `.github/workflows/`, `packaging/`, docs)

The QA configuration and cheating audit were done directly.

**Method:**

- Every confirmed finding was checked by reading the code. Most runtime findings were also reproduced with `php bin/phpxq jq|yq …`.
- I re-ran the headline reproductions (S1, S5, Q1, C1) myself.
- Infection and coverage figures come from the last full pipeline log in the working tree (`var/qa/full-pipeline.log`, 2026-10-05). The full pipeline was not re-run.
- No source file was edited.
- Reproduction inputs for the yq findings are in `untracked/scratch/rev/`.

**Severity scale:**

- **critical:** a small untrusted input crashes the process or hangs it without bound.
- **high:** a large correctness, availability or QA-integrity defect.
- **medium:** a real defect with limited reach.
- **low / info:** hygiene.

## Index

| ID  | Sev      | Area             | Title                                                                                                                |
| --- | -------- | ---------------- | -------------------------------------------------------------------------------------------------------------------- |
| Q1  | high     | QA / cheating    | 70% of Infection mutants are silently skipped, so the 90% MSI floor measures under a third of the code               |
| Q2  | medium   | QA strictness    | PHPUnit does not fail on deprecations, notices, skipped tests or test output, and does not require coverage metadata |
| Q3  | medium   | QA strictness    | PHPStan lacks bleedingEdge and the opt-in strictness parameters                                                      |
| Q4  | medium   | QA strictness    | No line-coverage floor; type-coverage floors sit at 95                                                               |
| Q5  | medium   | QA / DBF         | `UnguardedAliasRecursionRule` misses the merge-key recursion in `NodeTools::pairs` (see S1)                          |
| Q6  | low      | QA / clutter     | Stale "blanket entry" prose in both `known-gaps.txt` files                                                           |
| S1  | critical | Security / yq    | A YAML merge key that merges its own mapping segfaults PHP                                                           |
| S2  | critical | Security / yq    | No alias-expansion budget (billion laughs)                                                                           |
| S3  | critical | Security / yq    | Merge-key fan-out causes exponential work                                                                            |
| S4  | high     | Security / both  | The jq and yq expression parsers have no nesting limit and segfault (yq: reachable from data via `eval`)             |
| S5  | high     | Security / jq    | Invalid UTF-8 from argv, `--rawfile` or program literals gives an uncatchable internal error                         |
| S6  | medium   | Security / yq    | `--split-exp` file names come from the data: stream wrappers and `../` are accepted                                  |
| S7  | medium   | Security / yq    | The XML encoder does not escape names, comments or processing instructions (markup injection)                        |
| S8  | medium   | Security / yq    | Regex engine errors are swallowed, so `test`, `match` and `sub` return wrong answers silently                        |
| S9  | medium   | Security / both  | Small programs or inputs force huge allocations (props padding, string repeat, sparse index)                         |
| S10 | medium   | Security / CLI   | The Xdebug re-exec drops the user's `php -d` settings (for example a `memory_limit` cap)                             |
| S11 | medium   | Supply chain     | `qa.yml` actions are tag-pinned; a job with write access runs them                                                   |
| S12 | medium   | Supply chain     | Release assets are unsigned and have no provenance; `SHA256SUMS` sits in the same release                            |
| S13 | low      | Security / jq    | `RegexTranslator` copies `/` unescaped in `(*…)` and `(?(…)`; PCRE verbs pass through                                |
| S14 | low      | Security / yq    | `GoRegex` escaping of `~` breaks on `\~`                                                                             |
| S15 | low      | Security / yq    | Float-to-int casts produce internal errors (XML, Lua, `from_unix`, slices); `mb_chr` false is unguarded              |
| S16 | low      | Supply chain     | `install.sh` does not enforce HTTPS; `--links` replaces foreign symlinks; INT/TERM trap does not exit                |
| S17 | low      | Supply chain     | No `persist-credentials: false` in jobs with write permission                                                        |
| C1  | high     | Correctness / jq | `until`/`while` fail after 20000 iterations (no tail-call optimisation)                                              |
| C2  | medium   | Correctness / yq | A string key `"<<"` is emitted unquoted and becomes a merge key on re-read (data loss)                               |
| C3  | medium   | Correctness / yq | Merge sources are resolved differently by navigation and by the encoders                                             |
| C4  | medium   | CI               | Fork PRs never get the required QA gate; PRs fixed by autofix wait for a check that never runs                       |
| C5  | medium   | Docs             | The RELEASING.md recovery procedure conflicts with the tag ruleset the same file requires                            |
| C6  | low      | Correctness      | Compile errors hard-code `line 1`; XML entity error lines are wrong                                                  |
| C7  | low      | Correctness / yq | `FormatCalls` keeps mutable state between calls, so `@yaml` output depends on evaluation order                       |
| C8  | low      | Release          | Dead pre-release code path; `release-backmerge` can open duplicate PRs; `differential-jq` can run stale cases        |
| P1  | high     | Perf / jq        | `reduce`/`foreach` accumulation is O(n²)                                                                             |
| P2  | high     | Perf / jq        | `add` over objects, and object `+`/`*`, are O(n·m)                                                                   |
| P3  | high     | Perf / jq        | `indices` on non-ASCII strings is O(n²)                                                                              |
| P4  | high     | Perf / YAML      | One non-ASCII byte makes `Scanner::colAt` quadratic                                                                  |
| P5  | medium   | Perf / jq        | `gsub` concatenation and multi-key `del` are quadratic                                                               |
| P6  | medium   | Perf / yq        | Quadratic spots: global `match`, XML entities, HCL `find`, `[..]` deep copy, yq `reduce`                             |
| P7  | medium   | Perf / jq        | Input files are read whole into memory (stdin is streamed)                                                           |
| P8  | low      | Perf / JSON      | Sorting arrays of objects re-sorts keys on every comparison                                                          |
| D1  | low      | Code quality     | Duplicated helpers that behave differently (UTF-8, shell, base64, URI, scalar resolvers, format list)                |
| D2  | low      | Code quality     | Depth limits are scattered (500/1000/10000) and the error message is misleading                                      |
| D3  | low      | Code quality     | yq errors use jq-style text; `GoRegex::compile` discards warnings; a dead branch in `Traversal::descend`             |
| L1  | low      | Clutter          | The PHAR ships an unused Composer autoloader; about 170 lines of no-op deploy-key steps in `qa.yml`                  |
| L2  | info     | Clutter          | `src/PHPStan/CLAUDE.md` is a guard doc inside production `src/`                                                      |

---

## 1. QA configuration and cheating audit

### Q1 (high): 70% of mutants are skipped, so the MSI floor is mostly hollow

- **Where:**

  - `qaConfig/qa.php:26` sets `withInfectionFloors(90, 90)`.
  - Infection uses the generic `vendor/lts/php-qa-ci/configDefaults/generic/infection.json`, which has `"timeout": 10`.
  - `qaConfig/phpunit.xml:29-31` puts the conformance suite in the default run.

- **Evidence:** in the last full run (`var/qa/full-pipeline.log`, Infection section; `var/qa/infection/summary-log.txt`), 27103 mutants were generated, of which:

  - 7098 were killed
  - 649 escaped
  - 256 timed out
  - **19095 were skipped ("mutants required more time than configured")**

  It still reported "Mutation Code Coverage: 100%, Covered Code MSI: 91%" and the gate passed.

- **Cause:**

  - Infection skips a mutant when the estimated run time of the tests covering it is longer than `timeout`.
  - `GapAwareConformanceTest` runs every upstream case and covers almost all of `src/`, with no `#[CoversClass]`.
  - So for about 70% of mutants, the covering set includes a test that is far too slow, and Infection never runs them.
  - Skipped mutants are left out of the MSI denominator. The 90% floor is therefore computed on about 8000 of 27000 mutants, and nothing reports the gap.

- **Scenario:** a regression in, for example, the YAML scanner whose only killing test is a conformance case goes unmeasured. A test-weakening change in those areas cannot move the MSI.

- **Fix:**

  - Run Infection against the `unit` suite only (`--test-framework-options="--testsuite=unit"`, via a project `infection.json` override or a php-qa-ci option).
  - Or split `GapAwareConformanceTest` into per-case data-provider tests, so the per-test timing lets Infection pick fast covering tests.
  - Then add a cap on the skipped ratio, and raise it upstream in php-qa-ci: a large "skipped" count should fail the lane.

- **Cheating label:** not deliberate, but it is exactly the "gate green, measurement empty" pattern the audit looks for.

### Q2 (medium): PHPUnit strictness gaps

- **Config:** `qaConfig/phpunit.xml:9` sets only `failOnRisky="true"`.
- **Runner flags:** the php-qa-ci runner adds `--strict-global-state --fail-on-risky --fail-on-warning` (log line 417).
- **Missing:**
  - `failOnDeprecation`, `failOnPhpunitDeprecation` and `failOnNotice`. Deprecations and notices are displayed but do not fail the run, and PHP 8.5 deprecations would slip through. `ErrorGuard` converts *runtime* warnings, but not deprecations raised inside tests.
  - `failOnEmptyTestSuite`.
  - `failOnSkipped`. Three `markTestSkipped` calls exist, all environmental (`/dev/full`, directory-as-stream: `tests/Unit/Jq/Cli/JqApplicationTest.php:818`, `JqApplicationInputSourceTest.php:478`, `JqApplicationOutputWriterTest.php:73`). Acceptable, but in CI they would hide a missing capability.
  - `beStrictAboutOutputDuringTests`.
  - `requireCoverageMetadata` / `beStrictAboutCoverageMetadata`. 218 unit test files carry no `#[CoversClass]`, so coverage is incidental: a test of A that executes B counts as coverage of B. This also feeds Q1.
- **Fix:** add these attributes, then `#[CoversClass]` across the unit tests.

### Q3 (medium): PHPStan strictness could be tightened

- **Already in place:**
  - `qaConfig/phpstan.neon` runs `level: max` with strict-rules, deprecation-rules, phpstan-phpunit and type-coverage, all loaded via the extension-installer.
  - Thirteen opt-in rules, and three project DBF rules.
  - There is **no baseline and no `ignoreErrors`**, and there are zero `@phpstan-ignore` in `src/` and `tests/`. Good.
- **Not enabled:**
  - `includes: phar://phpstan.phar/conf/bleedingEdge.neon`
  - `checkUninitializedProperties`
  - `checkImplicitMixed`
  - `checkBenevolentUnionTypes`
  - `checkMissingCallableSignature`
  - `checkTooWideReturnTypesInProtectedAndPublicMethods`
  - `reportPossiblyNonexistentGeneralArrayOffset` and `reportPossiblyNonexistentConstantArrayOffset`
  - `reportAnyTypeWideningInVarTag`
  - `exceptions.check.missingCheckedExceptionInThrows` / `tooWideThrowType` (the codebase uses typed exceptions such as `JqCompileException` and `EvaluationException`)
- **Why it matters:** `reportPossiblyNonexistentGeneralArrayOffset` would have flagged the `$text[$i + 1]` out-of-range reads in `Unicode::codepoints` (S5).
- **Fix:** enable them one at a time and fix the code the new checks report. Do not add a baseline.

### Q4 (medium): coverage floors

- **Line coverage:**
  - The last run shows Lines 97.07%, Methods 85.38%, Classes 61.65%.
  - No line or method coverage floor is configured (`qaConfig/qa.php`), so coverage can fall without failing anything.
  - Fix: add a ratchet at the current value (php-qa-ci does not expose one; raise that upstream, or add a clover check step).
- **Type coverage:** `withTypeCoverageFloors(95, 95, 95, 95)` (`qa.php:28`). The comment says "a floor only ever moves up". For a PHP 8.5 codebase with no external dependencies, 100 should be achievable for return, param and constant types. Measure, then raise to the measured value.
- **Mutation:** `withInfectionFloors(90, 90)` is reasonable, but only after Q1 is fixed.

### Q5 (medium): the DBF net has a hole

- **Rule:** `qaConfig/PHPStan/Rules/UnguardedAliasRecursionRule.php:34-66` targets exactly the S1 bug class: recursion through alias resolution with no cycle guard.
- **Where it fails:**
  - `NodeTools::pairs()` (`src/Yq/Format/Codec/NodeTools.php:121-122`) recurses through `self::mergeSources($value)`. That resolves aliases inside a helper the rule does not treat as a resolver: `RESOLVER_METHODS` is only `deref` and `unwrap`, and the recursion is `pairs → pairs` with the alias resolved in `mergeSources`.
  - The rule therefore passes while the bug exists, and the process segfaults.
- **Fix (per this project's DBF method):**
  1. Extend the rule to treat any method whose return comes from `aliasTarget`/`unwrap`/`deref` as a resolver, transitively within the class.
  2. Prove it fires on `NodeTools::pairs`.
  3. Then fix S1.

### Q6 (low): stale gap-file prose

- **Where:** `tests/Conformance/Jq/known-gaps.txt:13-14` and `tests/Conformance/Yq/known-gaps.txt:13-14` still say "the blanket entry below is replaced by specific … entries". No blanket entry remains.
- **Fix:** delete those sentences.

### Cheating audit: clean areas (confirmed)

- **Suppressions:** none in `src/`, `tests/`, `qaConfig/`, `scripts/` or `bin/`.
  - No PHPStan baseline.
  - No `@phpstan-ignore`, `phpcs:ignore`, `@codeCoverageIgnore` or `@infection-ignore`.
  - No `markTestIncomplete`.
- **`#[CoversNothing]`:** 9 uses, all on tests of `tests/Support` or `qaConfig` rules. Those classes are outside the coverage `<source>` (src only), so the attribute is accurate, not abuse.
- **`composer-dependency-analyser.php:15,22`** ignores unknown symbols only for `qaConfig` (PHPStan classes live in the phar) and `tests/Fixtures/Defence`. Justified.
- **Known gaps:**
  - jq has 1 entry: `jq.test:2337`, a test-harness limitation.
  - yq has 7 entries: frozen-clock datetime, shuffle randomness, `system` deliberately unsupported, and 2 damaged upstream fixtures.
  - All entries are specific and justified. The gate fails when a known gap starts passing (`tests/Conformance/GapAwareConformanceTest.php:18-20`), which is a proper ratchet.
- **`withIgnoredPaths`:**
  - `qa.php:20` covers vendored upstream shell suites only.
  - `qa.php:23` covers deliberate defect fixtures.
- **`continue-on-error`:** used only at `release.yml:156`, for the macOS binary jobs (`optional: true`). There is a loud failure step (`:211`), and the decision is documented (`:20-21`).

---

## 2. Security

### S1 (critical): merge-key cycle segfault

- **Where:** `src/Yq/Format/Codec/NodeTools.php:121-122`. `pairs()` → `mergeSources()` → `pairs()`, with no visited set and no depth limit.
- **Repro:** `printf 'a: &a {x: 1, <<: *a}\n' > f.yml; yq -o json . f.yml` gives **exit 139 (SIGSEGV)**. Re-verified by me.
- **Reach:** the json, props, shell, lua, toml and hcl encoders.
- **Fix:** keep a visited set keyed by `spl_object_id($mapping)`. On re-entry, either skip (go-yaml semantics) or raise an `EvaluationException`. Do Q5 first.

### S2 (critical): no alias-expansion budget (billion laughs)

- **Where:** aliases stay as references (`src/Yaml/Parser/StreamParser.php:250-255`), but every encoder and `explode` (`src/Yq/Runtime/Anchors.php`) expands them without a budget.
- **Repro:** the classic 9-level `[*a ×10]` file (about 400 bytes).
  - `yq -o json .` was still running after 30 s and 1 GB of memory.
  - `explode(.)` ran out of memory.
- **Fix:** count expanded nodes per document and fail past a cap or ratio, as go-yaml's "excessive aliasing" check does.

### S3 (critical): merge fan-out causes exponential work

- **Where:** `src/Yq/Runtime/Traversal.php:296-338` and `NodeTools::pairs`. `MAX_MERGE_DEPTH = 32` bounds depth but not breadth, and nothing is cached.
- **Repro:** an 8-level chain where each level is `<<: [*prev ×10]`. A plain `.h.x` lookup takes more than 20 s.
- **Fix:** memoise each mapping's merged pairs for one evaluation, and share the S2 budget.

### S4 (high): no nesting limit in the expression parsers

- **jq:**
  - `src/Jq/Parser/Parser.php` and `src/Jq/Runtime/Eval/NodeCompiler.php` recurse without a cap.
  - Compilation runs before the `EvaluationStack` fiber starts (`JqApplication.php:125` comes before `:131`).
  - A 400k-deep `[1,1,…]` program segfaults. With pcov loaded, 10k-deep parentheses are enough.
  - jq itself rejects nesting deeper than 10000.
- **yq:**
  - `src/Yq/Expression/Parser/PrattParser.php` has the same problem. It is **reachable from data**: `yq 'eval(.e)'` with `e: "((((…100k…"` gives exit 139.
  - The parser is also super-linear: 2000 nested `[` take 4.7 s.
- **Fix:** add a depth counter at the recursive entry points and raise the syntax exception past 10000 (`Node::maxDepth()` already exists for the data side).

### S5 (high): invalid UTF-8 gives an uncatchable internal error

- **Where:** `src/Jq/Builtin/Core/Unicode.php:46-63` reads `$text[$i+1..3]` without checking bounds.
- **Why the input is not cleaned:**
  - Piped input is sanitised (`InputSource::scrub`).
  - `--arg`, `--args` and `--jsonargs` (`src/Jq/Cli/Options/OptionParser.php:73-77,339`), `--rawfile` (`:430-431`) and program string literals are not.
- **Repro:** `jq -n --arg x $'a\xff' 'try ($x|explode) catch "c", "after"'` prints `internal error: Uninitialized string offset 2` and exits 5. `try` does not catch it and "after" is never printed. Re-verified by me.
- **Related:** `length` and `explode|length` disagree on `--rawfile` binary data.
- **Fix:** sanitise every external string with `Utf8::sanitize`, as jq does, and bounds-check the decoder.

### S6 (medium): `--split-exp` path taken from data

- **Where:** `src/Yq/Cli/SplitFileWriter.php:44-55`.
- **Repro:** documents named `file:///…/x.yml` or `../escaped.yml` are written outside the working directory. `php://filter` and `ftp://` wrappers are also accepted, which is attack surface Go yq does not have.
- **Fix:** reject any `scheme://` name, or prefix the path explicitly. Optionally confine output to the current directory.

### S7 (medium): XML encoder markup injection

- **Where:** `src/Yq/Format/Codec/XmlEncoder.php:118` (attribute names), `:160` (processing instructions), `:188` (comments); element names are also unvalidated.
- **Repro:** the YAML comment `# c --> <evil/>` comes out as `<!-- c --> <evil/> -->`. A key such as `"x><evil/><y"` injects elements.
- **Fix:** reject `--` and `?>`, and validate XML Names. Go `encoding/xml` errors in these cases.

### S8 (medium): regex errors swallowed in yq

- **Where:** `src/Yq/Runtime/GoRegex.php:64,77,82,107`. An engine error means `test` returns false, `match` returns `[]` and `sub` returns its input.
- **Repro:**
  - `"aaaa…(40)c" | test("(a+)+b|c")` returns `false` when it should be `true`, because the backtrack limit is hit.
  - Invalid-UTF-8 subjects (from `@base64d`) make every regex return "no match".
  - `Compare::glob` has the same unchecked pattern.
- **Contrast:** the jq side handles this correctly and raises a jq error.
- **Fix:** check `preg_last_error()` and throw `EvaluationException`.

### S9 (medium): small inputs force huge allocations

- **Cases:**
  - Props: `a.999999999 = x` (16 bytes) pads a sequence with a billion nulls and runs out of memory (`PropsDecoder.php:43`).
  - yq `"ab" * 1e12`.
  - yq `.[100000000] = 1` hangs.
  - jq `.[536870911]=1` (`PathOps.php:32,177`) and `"x"*2147483647` (`Arithmetic.php:24,277`).
- **Why it matters:** CLI `memory_limit` defaults to -1, and memory fatals cannot be caught by `try`. jq shares the jq-side cases.
- **Fix:** cap sparse-index padding and string repeats (for example at 2^28 bytes or elements) with a catchable error. Consider setting a default `memory_limit` in `bin/phpxq`.

### S10 (medium): the Xdebug re-exec drops ini settings

- **Where:** `src/Cli/Xdebug.php:38` calls `pcntl_exec(PHP_BINARY, $argv, …)` without the original `-d` options.
- **Repro:** with `XDEBUG_MODE=develop`, a run under `php -d memory_limit=64M` succeeds unbounded in the child.
- **Fix:** diff `ini_get_all()` against the defaults and pass each changed value back as `-d`.

### S11 (medium): tag-pinned actions in `qa.yml`

- **Where:** `.github/workflows/qa.yml:121,137,279,296` use `actions/checkout@v4` and `shivammathur/setup-php@v2`.
- **Why it matters:** the `autofix` job has `contents: write`, and `gate` is the required check on `main` and `release`. `release.yml` already pins SHAs.
- **Fix:** pin to SHAs. If this template is vendored from php-qa-ci, fix it upstream.

### S12 (medium): no release signing or provenance

- **Where:** `install.sh:102-110` and `scripts/release-assets.bash:42-49`.
- **Problem:** `SHA256SUMS` comes from the same release as the binary, so it catches corruption but not a swapped binary. `docs/RELEASING.md:285` already lists this as a known limit.
- **Fix:** add `actions/attest-build-provenance`, sign `SHA256SUMS` with cosign or minisign, and verify the signature in `install.sh` when the verifier tool is present.
- **Unverified, related:** `scripts/build-binary.bash:111` runs `spc download --prefer-pre-built` with no repo-pinned hashes for the PHP source or pre-built libraries. The `spc` binary itself is pinned (`packaging/tools.env`).

### S13 (low): `RegexTranslator` `/` passthrough

- **Where:** `src/Jq/Builtin/Regex/RegexTranslator.php:273-278,298-303` copies `/` unescaped. `test("(*/)")` leaks the PHP message "Unknown modifier".
- **Not exploitable:** the trailing `/u` makes injected modifiers invalid.
- **Related:** PCRE verbs such as `(*LIMIT_MATCH=1)` are accepted where Oniguruma rejects them.
- **Fix:** escape `/` in those branches, and reject `(*`.

### S14 (low): `GoRegex` escaping of `~`

- **Where:** `GoRegex.php:39`. `str_replace('~','\~')` turns `\~` into `\\~`, so `test("\\~")` errors.
- **Fix:** use a delimiter that cannot appear in the pattern, or escape it properly.

### S15 (low): float-to-int "internal error"

- **Where:** PHP 8.5 errors on out-of-range casts, and `ErrorGuard` turns that into an internal error with exit 5. Affected sites:
  - `XmlReader.php:468` (`&#x99999999999999999999;`)
  - `LuaReader.php:376` (also a TypeError from `mb_chr` returning false on `\u{110000}`)
  - `DateCalls.php:76` (`1e30|from_unix`)
  - `Args.php:78` and `Evaluator.php:264` (`.[0:1e30]`)
- **Fix:** range-check before casting.

### S16 (low): `install.sh` hardening

- **Where:**
  - `install.sh:83`: no `--proto '=https' --proto-redir '=https' --tlsv1.2`.
  - `:85`: the wget fallback has no `--https-only` and no retries.
  - `:123-127`: `--links` silently replaces an existing `jq`/`yq` symlink (for example a mise or asdf shim).
  - `:99`: the INT/TERM trap cleans up but does not exit.
- **Fix:** add the TLS flags, skip existing foreign links, and use `trap … EXIT` plus `trap 'exit 130' INT TERM`.

### S17 (low): persisted credentials

- **Where:** no checkout sets `persist-credentials: false` (`release.yml:224,315`, `release-pr.yml:53`), and Composer plugins run in jobs with write permission.
- **Fix:** set it in the `release` job, which pushes via `gh` with `GH_TOKEN`.

**Security positives:**

- yq tags never construct objects. There is no `unserialize` or `eval`, and `system` is refused.
- The XML parser is hand-written with no DTD support, so XXE is impossible.
- Every input parser enforces a depth limit (200k-deep JSON, YAML, TOML, Lua, HCL and XML all fail cleanly).
- The YAML scanner rejects invalid UTF-8.
- `@sh`, `@csv`, `@tsv`, `@html`, `@uri` and `@base64d` are correct in jq.
- Module paths reject `..`.
- The jq regex cache is bounded at 512 entries, and backtrack-limit failures come back as jq errors.
- `-i` writes atomically.
- `release.yml` follows least privilege: SHA pins, `env:`-passed event values, and no `pull_request_target`.
- Box and spc are pinned by version and SHA-256, and the PHAR build is checked for reproducibility.

---

## 3. Correctness

- **C1 (high):**
  - **Where:** `until` and `while` are recursive jq definitions (`src/Jq/Builtin/Core/Prelude.php:48-49`), and `MAX_CALL_DEPTH = 20000` (`EvaluationStack.php:26`).
  - **Repro:** `jq -n '0|until(.>=30000;.+1)'` fails with `Evaluation too deep` (exit 5). Re-verified by me. jq applies tail-call optimisation and succeeds.
  - **Fix:** implement both natively, as `repeat` and `recurse` already are, and/or add TCO for self-tail calls in `CallOp`.
- **C2 (medium):**
  - **Where:** `src/Yaml/Emitter/YamlWriter.php:538` (`$force = '<<' !== $value`) leaves a string key `"<<"` unquoted.
  - **Repro:** `{"<<":{"a":1},"b":2}` through json → yaml → json comes back as `{"b":2}`.
  - **Fix:** check against Go yq before changing anything, because it may be intentional parity.
- **C3 (medium):**
  - **Where:** `NodeTools.php:251-269` (encoders) accepts only aliases as merge sources. `Traversal.php:161-179` (navigation) also accepts inline maps.
  - **Effect:** with `<<: {a: 1}`, `.a` returns 1 but `-o json` drops it.
  - **Fix:** use one shared resolver.
- **C4 (medium):**
  - **Where:** `qa.yml:120-124` checks out `github.head_ref` from the base repo, which a fork PR does not have, so `autofix` fails and `gate` skips.
  - **Second problem:** after `autofix` pushes with `GITHUB_TOKEN` (`:243-259`), the new head has no check run, so branch protection waits forever.
  - **Fix:** skip `autofix` for forks, and dispatch `qa.yml` after the push, as `release-pr.bash:100` already does.
- **C5 (medium):**
  - **Where:** `docs/RELEASING.md:215-216` requires a `v*` tag ruleset that blocks deletion. The recovery steps at `:266-270` delete the tag.
  - **Fix:** document an admin bypass, or change the recovery to "ship the next patch".
- **C6 (low):**
  - `NodeCompiler.php:263,605,610` hard-code `line 1` in compile errors.
  - `XmlReader.php:465` never resets `errorLine`, and uses `strpos` for the first occurrence, so error lines are wrong.
- **C7 (low):** `FormatCalls.php:35,125,143` keeps a mutable `$decodedWithoutFinalNewline`. `{"b":1}|@yaml` differs depending on whether `@yamld` ran earlier.
- **C8 (low):**
  - The pre-release path is dead: `SemVer.php:13` rejects suffixes, but `scripts/lib/packaging.bash:54`, `release-preflight.bash:71-72` and `release.yml:257-264` handle them.
  - `release-backmerge.bash:39-43` checks only for head `release`, so it can open a second PR.
  - `scripts/differential-jq.bash:20` does not check the generator's exit status, so stale `cases.tsv` cases run under a new seed.

## 4. Performance (times measured on this host)

- **P1 (high):**
  - **Cost:** `reduce range(n) as $i ([]; .+[$i])` takes 4.3 s at 40k items and more than 30 s at 80k. Object `.[k]=v` takes 27 s at 80k.
  - **Cause:** the accumulator is referenced twice (`ReduceOp.php:34-46`), so every write copies it, and `JsonObject::with` (`src/Json/JsonObject.php:70-76`) copies all members.
  - **Fix:** update the accumulator in place when its refcount is 1, and add a bulk builder.
- **P2 (high):**
  - **Cost:** `[range(80000)|{(tostring):.}]|add` takes 24 s (`CollectionFunctions.php:484-494`, `array_replace` per item). `{} + $o` with 160k keys takes more than 60 s (`Arithmetic.php:79-86,290-298`, `with()` per key).
  - **Fix:** build a single member array.
- **P3 (high):**
  - **Cost:** `"é"*40000|indices("é")` takes 32 s.
  - **Cause:** `Unicode::offsetOf` recounts from the start of the string for every hit (`StringFunctions.php:178-181`, `Unicode.php:101-104`).
  - **Fix:** count incrementally from the previous hit.
- **P4 (high):**
  - **Where:** `src/Yaml/Token/Scanner.php:465-473`. `colAt()` counts continuation bytes from the start of the line for every token, and the multibyte flag is global to the input.
  - **Cost:** a 110 KB single-line flow map with one `é` takes 7.0 s, against 1.5 s without it. A 318 KB one did not finish in 60 s.
  - **Fix:** cache the last offset and column per line.
- **P5 (medium):**
  - `gsub` concatenates strings in a loop (`RegexBuiltins.php:252-254`): 400k matches take 12 s.
  - `del(.[])` on a 40k-key object takes 5.6 s (`PathOps.php:251-258`, one `without()` per key).
  - **Fix:** `implode` the pieces once; copy once and `unset` each key.
- **P6 (medium), yq:**
  - Global `match` runs `mb_strlen(substr(...))` per match (`GoRegex.php:156,159`): 200 KB takes 41 s.
  - XML entities trigger `substr_count` per entity (`XmlReader.php:465`): 1.8 MB takes 16.7 s.
  - HCL `find()` is linear per attribute (`HclReader.php:221-246`): 20k keys take 42 s.
  - `[..]` deep-copies every result (`Evaluator.php:288`): 23 s at depth 4000.
  - yq `reduce` append (`ArithmeticOperator.php:92-106` plus the linear `Traversal::lookup`): 8k items take 44 s.
- **P7 (medium):**
  - **Where:** `InputSource.php:245` (`FileReader::read`) reads each file whole, while stdin is chunked (`:268-282`).
  - **Fix:** stream files through the same chunked reader.
- **P8 (low):** `src/Json/Values.php:151-152` re-sorts both objects' keys on every comparison, which affects `sort`, `group_by` and `unique` on arrays of objects. Fix: precompute sorted keys once per element (decorate–sort–undecorate).

## 5. Code quality

- **D1:** duplicated helpers that diverge.
  - `Text::chars` and `Unicode::characters` are identical, and both turn a `preg_split` failure into `[]` silently.
  - There are three `length` implementations and two `isAscii`.
  - `NodeCompiler::FORMATS` (`:58`) duplicates `FormatNameEnum`.
  - yq has two shell quoters (`StringFormats::shellQuote` and `FormatCalls::shell`) and two base64 decoders.
  - `@urid` (`FormatCalls.php:81`) skips the validation in `StringFormats::uriDecode`, so `"a%zz"|@urid` returns its input silently.
  - `CoreSchema` and `ScalarResolver` disagree: `1e400` is `!!float` in one and `!!str` in the other, and `0b` is unknown to `CoreSchema`.
- **D2:** depth limits are scattered.
  - Encoders use 500 (HCL, TOML) or 1000 (JSON, Lua, Shell, XML, Props); parsers use 10000.
  - A valid YAML document 1500 deep cannot be written as JSON, and the error says "(alias cycle?)".
  - Fix: one shared constant, plus real cycle detection.
- **D3:**
  - `src/Cli/FrontController.php:26` prints a jq-style `"%s: error (at <unknown>): internal error"` for yq as well.
  - `GoRegex::compile` swallows PCRE warnings with `set_error_handler(fn() => true)`.
  - `Traversal::descend:245-251` has an `elseif` that duplicates the code that follows it (a dead branch).

## 6. Leftover clutter

- **L1 (low):**
  - `bin/phpxq` uses its own PSR-4 closure and never loads `vendor/autoload.php`. Yet `scripts/build-phar.bash:73-74` runs `composer install --no-dev --classmap-authoritative`, and `box.json` has `dump-autoload: true`, so an unused autoloader ships in the PHAR.
  - `qa.yml:144-228,303-387` carries about 170 lines of no-op private deploy-key template steps.
  - `build-phar.bash:61-82` leaves `/tmp/phpxq-stage.*` behind when a build fails.
- **L2 (info):** `src/PHPStan/CLAUDE.md` is a "wrong location" guard doc inside production `src/`. It is harmless (not PHP), but it ships in any source export. Consider moving the guidance to `.claude/rules/`.
- **Checked and fine:**
  - The staged deletions of `src/.gitkeep`, `tests/.gitkeep` and `benchmarks/baselines/.gitkeep` are correct, since the directories are populated.
  - The benchmark baselines (about 58 KB) and `tests/Fixtures` (148 KB) are referenced and intentional.
  - `/var/`, `/dist/`, `/build/` and `/untracked/` are gitignored.
  - `src/PhpQaCi/FactorySealedBy.php` is generated by php-qa-ci, and its exclusion from analysis is documented (`phpstan.neon:11-15`).

## 7. Speculative / not verified

- `@uri` uses `ctype_alnum` (`FormatFunctions.php:122`), which follows the locale. If an embedding application calls `setlocale`, bytes 0x80 and above could pass through unencoded. Use an explicit ASCII range check.
- `OutputWriter.php:68` treats a 0-byte `fwrite` as a broken pipe, which could end output early on a non-blocking stdout (EAGAIN).
- `EvaluationStack` reserves about 312 MB of virtual fiber stack per run (20000 × 16 KB). This may fail with `FiberError` where memory overcommit is strict.
- `box.json:15` has `check-requirements: false` and there is no PHP version guard, so on PHP below 8.5 the PHAR probably fails with a parse error instead of a clear message.
- The `spc` build cache key does not include the PHP patch level, so a stale PHP 8.5.x could ship.
- Go yq may share the alias-bomb, props-padding and inline-merge behaviours. The fixes are worth having regardless.

## 8. Notable positives

- Zero suppressions of any kind and no baseline. PHPStan runs at max with strict, deprecation and phpunit rules, and type coverage is enforced.
- The project has its own Defence Before Fix PHPStan rules (`RecursionThroughNativeCallbackRule`, `UnguardedAliasRecursionRule`, `LoopInvariantConstructionRule`, `StringDiscriminatorRule`), each tested against fixtures.
- The conformance gate runs the upstream jq 1.8.2 and yq suites, with a two-way known-gap ratchet and very few, specific gaps.
- The test suite is large: 9478 tests and 248k assertions, run in random order with strict global state, and line coverage is 97%.
- Input parsers are depth-limited throughout, XML has no DTD or XXE surface, and `@sh` quoting is correct.
- Release engineering is careful: SHA-pinned `release.yml`, pinned and hashed build tools, a reproducible PHAR check, a strict preflight that re-runs just before tagging, and an atomic, checksum-verified installer.
