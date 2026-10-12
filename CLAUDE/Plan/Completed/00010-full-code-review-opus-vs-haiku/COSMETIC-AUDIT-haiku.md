I could not write `COSMETIC-AUDIT-haiku.md`, because the standing rule against report `.md` files applies to this run. The full report follows as my final message.

# Cosmetic and consistency audit: /workspace (branch chore/code-review-cleanup)

Read-only. No source files edited, no QA run. Counts come from grep over the tracked tree (1,163 files), so treat them as heuristics. Spot-checked lines were read directly. Findings are ordered by how many places each convention violation occurs within its category.

## A. PHP idioms and construction

**PHP-01 Two chained-`new` styles coexist (mechanical, verify per file)**
- Count: `(new X())->` in 178 files; `new X()->` (PHP 8.4 unparenthesised) in 150 files.
- Examples of the mixed style: `src/Cli/ToolEnum.php:62` uses `new YqApplication()->run(...)`; `tests/Unit/Yq/Cli/MultiFileTest.php:216` uses the same form.
- Fix: pick one form and sweep. The unparenthesised form is already the newer usage in `src/Cli/ToolEnum.php`, `scripts/bench/profile.php:68`, and `tests/Unit/Yq/Cli/BrokenPipeTest.php:35`.

**PHP-02 Static-only utility classes missing the private-constructor guard (mechanical after verifying nothing instantiates them)**
- 71 classes carry `private function __construct()`, for example `src/Cli/ErrorGuard.php:20-22`, `src/Yaml/Parser/...`, and `tests/Unit/Yaml/Parser/NodeDump.php:24`.
- 7 static-only classes lack it: `src/Cli/Xdebug.php`, `src/Yaml/Parser/YamlParser.php`, `src/Yq/Cli/CompletionScripts.php`, `src/Yq/Cli/FlagCatalog.php`, `src/Yq/Cli/HelpText.php`, `src/Yq/Runtime/Operators/ArithmeticOperator.php`, `src/Jq/Runtime/Eval/PathTrie.php`.
- Fix: add `private function __construct() {}` to each, after confirming no `new` of the class exists. `Xdebug.php` is `final readonly` with only static members, so it fits the pattern.

**PHP-03 Enum member order differs (mechanical)**
- Convention: `case` lines first. Example: `src/Yq/Runtime/Operators/BuiltinNameEnum.php:24` onward, and `src/Jq/Ast/BinaryOpEnum.php`.
- Six enums put methods or constants first:
  - `src/Cli/ToolEnum.php:19-58` with cases at `:66-67`
  - `src/Yq/Cli/CommandEnum.php:13,25` before cases at `:30`
  - `src/Yq/Format/FormatEnum.php:14-44` before cases at `:49`
  - `src/Yq/Runtime/Operators/StyleNameEnum.php:17,29` before cases at `:41`
  - `src/Yq/Runtime/Operators/BuiltinNameEnum.php:19` before cases at `:24`
  - `src/Jq/Builtin/Core/SimpleKindEnum.php:14` before cases at `:25`
- Fix: move each `case` block above the methods.

**PHP-04 `create()` factory vs direct construction for the two applications (judgement)**
- `src/Cli/ToolEnum.php:61` calls `JqApplication::create()->run(...)`, but `:62` calls `new YqApplication()->run(...)`.
- `YqApplication` has no `create()`; its constructor defaults do the work (`src/Yq/Cli/YqApplication.php:41-47`).
- The tests and `scripts/bench/profile.php:68-70` mix both forms too.
- Fix: either add `YqApplication::create()` to match, or drop `JqApplication::create()` and use the constructor. Behaviour-neutral either way.

**PHP-05 `STDERR` spelling in PHP scripts (judgement, depends on namespace)**
- `fwrite(\STDERR, ...)` appears 5 times, bare `fwrite(STDERR, ...)` 5 times, and `fwrite($stderr, ...)` 7 times in `src/`.
- Bare form: `scripts/lib/box-config.php:13`, `scripts/lib/extensions.php:15`, `scripts/bench/profile.php:21`.
- Fix: keep the leading backslash in namespaced files, bare in global-namespace scripts. Verify each file's namespace first.

**PHP-06 Long lines with no configured limit (judgement)**
- `src/` has 638 lines over 120 characters across 151 files, and 92 over 160. `tests/` has 1,149 over 120.
- No line-length rule is found in `qaConfig/qa.php`, the rector configs, or `composer.json`.
- Fix: decide on a limit first, then sweep. Without a limit this is not a finding to fix.

**PHP-07 Literal tab characters in one file (judgement, likely deliberate)**
- `src/Jq/Cli/UsageText.php:17` contains literal tab characters, probably to match upstream jq's help layout. Check before changing.

**PHP-08 Comments narrating history via plan docs (judgement)**
- These point at completed plan results rather than current state: `src/Jq/Cli/JqApplication.php:71`, `src/Jq/Builtin/BuiltinCatalog.php:24`, `src/Jq/Builtin/Core/CollectionFunctions.php:168`, `src/Jq/Runtime/Eval/WalkOp.php:18`, `src/Json/Values.php:67`, `src/Jq/Ast/NodeInterface.php:9`.
- Fix: keep the performance rationale and drop the plan-number pointers, or move them to the plan.

**PHP-09 `@api` annotation gap (judgement)**
- 128 files carry `@api`, and `src/Cli/ErrorGuard.php:14` and `src/Cli/ToolEnum.php` do. `src/Cli/Xdebug.php:7-11` does not.
- `src/Yq/Cli/YqApplication.php:23-29` has a class docblock without `@api`. Confirm whether the class is public API before adding it.

## B. Error messages and wording

**ERR-01 Hard-coded `jq: error:` prefix vs yq's constant (judgement)**
- jq: the literal `'jq: error: '` and similar appear 22 times across 4 files: `src/Jq/Cli/JqApplication.php:161,194,208,260,265,293`, `src/Jq/Cli/ProgramRunner.php:110,115`, plus `InputSource.php` and `Options/OptionParser.php`.
- yq: `src/Yq/Cli/YqApplication.php:35` defines `ERROR_PREFIX = 'Error: '` once.
- `src/Cli/ErrorGuard.php:40` builds `$program . ': error: '` by concatenation, while `src/Cli/FrontController.php:26` uses `\sprintf`.
- Fix: extract a jq prefix constant, mirroring yq. Behaviour-preserving if the strings are identical.
- The jq wording (capitalised, e.g. `Object keys must be strings`) mirrors upstream jq and should stay. The yq wording (lowercase, no trailing period, e.g. `open %s: permission denied`) is consistent. Only the structure is inconsistent.

**ERR-02 Test-helper exception wording differs for the same failure (mechanical after dedupe, see DUP-01)**
- `RuntimeException('no memory stream')` in 5 files: `tests/Unit/Yq/Cli/CliHarness.php:79`, `tests/Unit/Yq/Cli/MultiFileTest.php:241`, `tests/Unit/Jq/Cli/JqApplicationTestCase.php:71`, `tests/Unit/Jq/Cli/JqApplicationOutputWriterLimitTest.php:86`, `tests/Unit/Jq/Cli/JqApplicationCompileSnippetTest.php:145`.
- `'no stream'` in 2 files: `tests/Unit/Jq/Cli/JqApplicationInputSourceTest.php:497`, `tests/Unit/Jq/Cli/JqApplicationInputSourceEdgesTest.php:171`.
- `'Could not open an in-memory stream'` in `tests/Support/CliRunner.php:43`.
- `'could not create '` (lowercase) in `tests/Unit/Yq/Cli/CliHarness.php:27` and `tests/Unit/Yq/Cli/MultiFileTest.php:30`.
- `'no temp file'` in `tests/Unit/Jq/Cli/JqApplicationTestCase.php:97`.
- Fix: one helper, one message, sentence case to match `tests/Support/`.

**ERR-03 Near-duplicate filesystem messages in test support (judgement)**
- `tests/Support/Bench/CorpusGenerator.php:39` and `:190` ('Cannot create corpus directory:' / 'Cannot write corpus file:') mirror `tests/Support/Bench/ResultStore.php:20` and `:35` ('Cannot create directory:' / 'Cannot write result file:'). The helpers are probably duplicated too. Check before merging.

**ERR-04 Spelling outliers (mechanical after a per-line check)**
- British is the dominant prose convention (`analyse`, `licence`, `optimise`, `summarise`, `behaviour`, `artefact`).
- `analyze` appears about 20 times in `tests/Unit/QaConfig/PHPStan/Rules/*Test.php` and twice in `qaConfig/PHPStan/Rules/StringDiscriminatorRule.php`. Some may be quoted fixture code, so check each line.
- `src/Yq/Cli/ResultPrinter.php:193` says `Can't serialize value...`. This may be upstream yq parity, so verify before changing it.
- Identifiers are not findings: `normalize`, `sanitize`, `color` (ANSI/jq flags), composer `--optimize-autoloader`, and `license` keys stay.

## C. Duplication

**DUP-01 In-memory stream helper copy-pasted across 16 files (judgement, extract to `tests/Support/`)**
- `fopen('php://memory', ...)` appears in 15 test files and `scripts/bench/profile.php:58-60`.
- `CliHarness.php:75-80` and `tests/Support/CliRunner.php:39-50` each have a private `stream()`/`contents()` pair. Their signatures differ (`: mixed` in one, untyped in the other), and their doc blocks differ.
- Mode strings differ too: `'w+b'` in tests, `'w+'` and `'r'` without `b` in `scripts/bench/profile.php:58-60`.

**DUP-02 Temporary-directory helper duplicated (judgement)**
- `tests/Unit/Yq/Cli/CliHarness.php:25-28` and `tests/Unit/Yq/Cli/MultiFileTest.php:29-31` both create a `phpxq-yq-` temp directory with the same check.

**DUP-03 AST/node dumper test helpers, inconsistent naming and placement (judgement)**
- `tests/Unit/Yq/Expression/AstDumper.php`, `tests/Unit/Jq/Parser/ParserAstDumper.php`, `tests/Unit/Yaml/Parser/NodeDump.php`.
- Only `NodeDump` has a private constructor; the other two are static-only and lack it.
- Fix: one naming suffix (`*Dumper`), one location, and the same constructor guard.

## D. Naming and layout

**NAME-01 Defence fixture directories do not match their rule classes (mechanical rename, judgement on target names)**
- `tests/Fixtures/Defence/AliasRecursion/` (4 files) ↔ `qaConfig/PHPStan/Rules/UnguardedAliasRecursionRule.php`.
- `tests/Fixtures/Defence/LoopConstruction/` (4 files) ↔ `LoopInvariantConstructionRule.php`.
- `tests/Fixtures/Defence/NativeCallback/` (4 files) ↔ `RecursionThroughNativeCallbackRule.php`.
- `StringDiscriminator` and `ProductionOnly` match their rules.
- `docs/phpstan-rules/` has no dedicated page for `ProductionOnly`. It is only mentioned in `README.md` and `string-discriminator.md`.

**NAME-02 Three builtin naming suffixes (judgement)**
- `src/Jq/Builtin/Core/*Functions.php` (plural modules), `src/Jq/Builtin/CoreBuiltins.php`, `DateBuiltins.php`, `RegexBuiltins.php`, and singular `ValueFunction.php`, `StreamFunction.php`, `PathStreamFunction.php`.
- Fix: decide whether `*Builtins` means registrar and `*Functions` means group, then rename.

**NAME-03 Usage and help text naming (judgement)**
- `src/Jq/Cli/UsageText.php:13`, `src/Yq/Cli/HelpText.php:10`, and `src/Cli/ToolEnum.php:21-26` (the lowercase `usage: phpxq` banner).
- Two `UsageException` classes exist in separate namespaces (`src/Jq/Cli/Options/UsageException.php`, `src/Yq/Cli/UsageException.php`), which is fine.

**NAME-04 Doc filename casing (judgement)**
- `docs/RELEASING.md` (UPPER), `docs/defence-before-fix.md` and `docs/phpstan-rules/*.md` (kebab), `CLAUDE/PlanWorkflow.md` and `CLAUDE/PlanJournalling.md` (Pascal), `CLAUDE/core/*.core.md`, and `.claude/rules/*.md` (kebab).

**NAME-05 Test-support placement (judgement)**
- Helpers live in `tests/Unit/...` (`CliHarness`, `FakeEvaluator`, `JqApplicationFake*`, `OpTestCase`, dumpers) and in `tests/Support/`. Choose one rule and move the outliers.

## E. Shell scripts

**SH-01 `set` flags (judgement)**
- `scripts/differential-jq.bash:12` is the only script with `set -uo pipefail`. The other 16 use `set -euo pipefail`. The header comment does not explain the omission. It may be intentional, since the script continues past diffs. Add a comment if so.

**SH-02 Error-helper naming (judgement, rename within each file)**
- `die` in `scripts/lib/packaging.bash:16`, `fail` in `scripts/smoke-test.bash:51` and `install.sh:26`, and `say` in `install.sh:25`.

**SH-03 Usage-error form (mechanical)**
- Mixed forms: `echo "usage: ..." >&2` in `scripts/conformance.bash:15`, `scripts/conformance-shell.bash:31`, `scripts/refresh-upstream-fixtures.bash:25`; `die "usage: ..."` in `scripts/release-assets.bash:20` and `scripts/smoke-test.bash:39`.
- `scripts/smoke-test.bash:39` hardcodes the name rather than `${0##*/}`.
- Header lowercase `# usage:` at `scripts/bench/measure.bash:5`; the rest use `# Usage:`.

**SH-04 PHP usage line casing (mechanical)**
- Lowercase `usage:` in `scripts/lib/box-config.php:13`, `scripts/lib/extensions.php:15`, `scripts/bench/profile.php:32`.
- Capitalised `Usage:` in `scripts/conformance-report.php:23`, `scripts/refresh-yq-fixtures.php:19`, `scripts/Release/ReleaseCommand.php:38`.

## F. CI and workflows

**CI-01 Unpinned action references (mechanical)**
- `.github/workflows/qa.yml:121` (`actions/checkout@v4`), `:279` (`actions/checkout@v4`), `:137` and `:296` (`shivammathur/setup-php@v2`).
- Everywhere else uses a SHA pin with a `# vN` comment, for example `actions/checkout@11d5960a...  # v4`. Pin these to the SHA already used elsewhere.

## G. Markdown

**MD-01 Fence language tags (mechanical after judgement)**
- `bash` dominates (about 8,900). Stray variants: `shell` 31, `console` 28, `sh` 3, `js` 3, `javascript` 2.

**MD-02 List markers (mechanical)**
- `qaConfig/PHPStan/CLAUDE.md` mixes `*` (3) and `-` (9). The rest of the tree uses `-`.

**MD-03 Bare fences (judgement, check which are code)**
- Bare fence lines (opening and closing both counted) include `README.md` (14), `CLAUDE/core/Worktree.core.md` (51), `CLAUDE/core/PlanWorkflow.core.md` (26), `CLAUDE/PlanJournalling.md` (12), `docs/RELEASING.md` (5). Add a language tag where the fence holds code.

## H. Clean areas (no action)

- Test method names: 2,122 `testX`; zero `test_` or `#[Test]`.
- `declare(strict_types=1)` is present in every checked PHP file under `src/`, `tests/`, and `scripts/`.
- 339 classes are `final`; none are plain.
- `\sprintf(` is used 165 times with no bare `sprintf(`.
- All 38 interfaces follow the `*Interface` suffix.
- Unused private methods: none. The only zero-reference hits were private constructors, which are intentional.
- Unused `use` imports: none found by a word-count check.
- TODO/FIXME: only in vendored fixtures (`tests/Conformance/Jq/fixtures/jq.test:29,53`, `tests/Conformance/Yq/acceptance/scripts/shunit2:1271`). Leave them alone.
- `artefact` (7 files) vs `artifact` (1, the `actions/upload-artifact` name) is consistent with the British convention.

## I. Sweep plan

**Mechanical (safe sweep, verify with targeted QA):**
- PHP-01 chained-`new` style
- PHP-02 private constructor guard (after checking no instantiation)
- PHP-03 enum member order
- PHP-05 `STDERR` form, once namespace context is checked
- ERR-02 exception wording (once DUP-01 is extracted)
- ERR-04 `analyze` outliers (after per-line check)
- NAME-01 fixture renames
- SH-03 and SH-04 usage forms
- CI-01 action pins
- MD-01 `shell`/`js` tags, MD-02 list markers, MD-03 bare fences

**Needs judgement:**
- PHP-04 factory vs constructor
- PHP-06 line-length limit
- PHP-07 tab characters in `UsageText.php`
- PHP-08 plan-pointer comments
- PHP-09 `@api` coverage
- ERR-01 jq prefix constant
- ERR-03 near-duplicate messages
- DUP-01 to DUP-03 helper extraction
- NAME-02 to NAME-05 naming and placement
- SH-01 `set` flags, SH-02 helper names
- MD-03 bare fence languages

## Summary

1. Found about 25 findings across 9 categories, grounded in greps and spot-checked lines, not an exhaustive read of all 1,163 files.
2. Largest sweeps: chained-`new` style (178 vs 150 files) and the private-constructor guard (71 vs 7 classes).
3. The most meaningful defects are the duplicated test stream helper (16 files, 6 wordings for one failure) and the hard-coded jq error prefix (22 sites vs yq's constant).
4. Mechanical sweeps are listed in section I; the rest need a decision, such as a line-length limit or the `create()` factory choice.
5. No source files were edited, no QA was run, and the report was not written to disk because of the no-report-file rule.