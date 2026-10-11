I sent the 10-line summary to team-lead. I did not write `REVIEW-haiku.md`, because the system rules forbid writing report files; the full findings are below instead.

## Haiku 5.5 review of phpxq

Reviewer model: Haiku 5.5 (claude-haiku-5-5). Read-only; the only files I created are scratch inputs under `untracked/scratch/`, which I left in place. I removed one traversal-probe output I had created outside the scratch directory.

Rating scale used below: critical / high / medium / low / info. "Confirmed" means I reproduced it or read the code path directly; "speculation" is marked.

### Index

| # | Sev | Finding | Location |
|---|---|---|---|
| 1 | High | Mutation floor (90/90) is never enforced in CI or release; no line-coverage floor exists | `qaConfig/qa.php:26`, `vendor/lts/php-qa-ci/src/Pipeline/Lane/InfectionTool.php:65`, `.github/workflows/qa.yml:140,299`, `.github/workflows/release.yml:105` |
| 2 | High | Autofix job runs PR code with a write token and the SSH deploy bundle; unpinned third-party actions | `.github/workflows/qa.yml:113-141`, `:144-167` |
| 3 | Medium | Regex `l` flag is O(n²) per match (confirmed timings) | `src/Jq/Builtin/Regex/RegexEngine.php:109-137` |
| 4 | Medium | UTF-8 char slicing re-splits the whole string per call (confirmed timings) | `src/Jq/Runtime/Eval/Text.php:40-56`, `Access.php:120-122` |
| 5 | Medium | Alias expansion on output has no budget (confirmed: 340-byte input gives 34 MB) | `src/Yaml/Node.php` (aliases are shared refs), output path in yq JSON encoder |
| 6 | Medium | `--split-exp` writes to a data-derived path, outside cwd (confirmed) | `src/Yq/Cli/SplitFileWriter.php:43-54` |
| 7 | Medium-low | Sparse index assignment allocates up to 2^29 nulls; constant duplicated | `src/Jq/Runtime/PathOps.php:32,177`, `src/Jq/Runtime/Eval/Assignment.php:24` |
| 8 | Medium-low | PHPUnit does not fail on skipped/incomplete/deprecation/notice; env-guarded skips vanish silently | `qaConfig/phpunit.xml`, `var/qa/full-pipeline.log:417`, `tests/Unit/Jq/Cli/JqApplicationTest.php:818` |
| 9 | Low | Whole-file globs in yq known gaps; README count mismatch; unmatched-ignore reporting disabled | `tests/Conformance/Yq/known-gaps.txt:20-21`, `README.md:177`, `qaConfig/composer-dependency-analyser.php:23` |
| 10 | Low | `install.sh` `ln -sf` replaces an existing symlink (only regular files are protected) | `install.sh:123-127` |
| 11 | Low | Duplicated CI blocks and stale comment about deferred tests | `.github/workflows/qa.yml:77-79` (stale), `:144-227` vs `:303-387` (duplicated) |
| 12 | Info | Backup file duplicates CLAUDE.md (gitignored) | `.CLAUDE.md.pre-inject` (byte-identical) |
| 13 | Info | Committed benchmark snapshots and generated `FactorySealedBy.php` ship in the PHAR | `benchmarks/baselines/*`, `src/PhpQaCi/FactorySealedBy.php` |
| 14 | Info | `call_user_func('pcntl_exec', ...)` indirection hides the call from static analysis | `src/Cli/Xdebug.php:38` |
| 15 | Info | Speculation: a 312 MB fiber stack reservation may fail on hosts with restrictive virtual-memory limits | `src/Jq/Runtime/EvaluationStack.php:51` |

### Details on the main findings

**1. Quality gates not enforced in CI (High).** php-qa-ci runs Infection only when Xdebug is loaded (`InfectionTool.php:65` returns "skipped"). Both the QA gate and the release QA job install PHP with `coverage: none`, so the mutation floor never runs there. The project has no line- or method-coverage floor (`qa.php` only sets Infection and type-coverage floors). Local numbers from the last run in `var/qa/phpunit_logs/coverage.clover`: statements 15,973/16,454 (97.1%), methods 1,583/1,854 (85.4%), conditionals not measured (0). The last local Infection summary (`var/qa/infection/summary-log.txt`) shows 19,095 of 27,103 mutants as "Skipped". The approximate MSI from those counts is about 92%, but I did not verify Infection's own printed figure, and the reason for the high skip count is unexplained. Suggested fix: run Infection and coverage in one CI job that installs Xdebug or PCOV, and add a line-coverage floor.

**2. Autofix job exposes secrets and a write token to PR code (High).** In `qa.yml`, the `autofix` job runs on `pull_request` with `contents: write`. It writes the SSH deploy keys into `~/.ssh` before `composer install`, then runs `vendor/bin/qa -t allCS`. Both steps execute PR-controlled code (composer plugins from the PR's lock file, and Rector and PHP-CS-Fixer configs). `actions/checkout` leaves the token persisted in `.git/config` by default. Fork PRs get a read-only token and no secrets, so exposure is limited to same-repo branches. Severity is High if non-maintainers can push branches. The same job also uses `actions/checkout@v4` and `shivammathur/setup-php@v2`, which are mutable tags, while `release.yml` pins SHAs. Suggested fix: pin all actions to SHAs, set `persist-credentials: false`, and move the autofix job off secrets (or require a maintainer label).

**3. Regex `l` flag is quadratic (Medium, confirmed).** `RegexEngine::search()` tries an anchored match at every position from the offset to the end, on each search, and never stops early. Timings for `[match("a"; "gl")]` on n characters: n=1000 0.85s, n=2000 3.1s, n=4000 12.2s; the same query without `l` takes 0.16s. A 20 KB input would take minutes. Suggested fix: bound the search, or find the longest match with a single pass.

**4. UTF-8 slicing is quadratic (Medium, confirmed).** `Text::slice` calls `preg_split('//u')` on the whole string for every slice. `$s[$i:$i+1]` over every index of an 8,000-character UTF-8 string takes 19.8s (4,000 characters: 4.7s). ASCII strings take the `substr` path and are much faster (4,000 characters: 0.32s). Suggested fix: cache the codepoint offsets per string, or use `mb_substr` with the offset table.

**5. Alias expansion has no budget (Medium, confirmed).** Each level of nested aliases multiplies output size by nine. A 340-byte six-level input produces 34 MB of JSON in 4.3s; seven levels would produce about 370 MB. This follows YAML alias semantics and matches upstream yq, but there is no cap. Suggested fix: count expanded nodes during output and fail beyond a limit.

**6. `--split-exp` path traversal (Medium, confirmed).** A document with `name: ../../split-escape.txt` was written two directories above the working directory. Upstream yq behaves the same way, but `phpxq` is likely to be run on untrusted YAML. Suggested fix: reject names containing `..` or absolute paths unless an explicit flag allows them.

**7. Sparse index assignment (Medium-low).** `[] | .[536870911] = 1` is permitted by `MAX_INDEX`. With the default CLI `memory_limit=256M` it fails cleanly with exit 5. With `memory_limit=-1` (the value in this environment) it allocates about 20M slots in 2.9s for the smaller 20M-index test, and the full range would consume about 8.6 GB. The constant is duplicated in `PathOps.php:32` and `Assignment.php:24`. Suggested fix: a single shared constant and a lower cap, or a memory check.

**8. PHPUnit strictness (Medium-low).** The pipeline's PHPUnit invocation (`var/qa/full-pipeline.log:417`) passes `--strict-global-state --fail-on-risky --fail-on-warning`, and displays but does not fail on skipped, incomplete, deprecation and notice. Three tests skip when `/dev/full` or a directory stream is unavailable (`JqApplicationOutputWriterTest.php:73`, `JqApplicationTest.php:818`, `JqApplicationInputSourceTest.php:478`). On macOS these silently lose coverage. Suggested fix: set `failOnSkipped`, `failOnIncomplete`, `failOnDeprecation` and `failOnNotice` in `phpunit.xml`, and replace the `/dev/full` dependence with a fake stream.

**9. Known-gap and ignore hygiene (Low).** `tests/Conformance/Yq/known-gaps.txt:20-21` uses whole-file globs (`shuffle.md: *`, `system-operators.md: *`), contrary to the file's own header. These can hide regressions in those documents. The README (`README.md:177`) says nine yq gaps, but the file has seven entries, two of them wildcards, so the count is unverified. `composer-dependency-analyser.php:23` disables reporting of unmatched ignores. `rector-php85.php:43` reads skip paths from `$_SERVER['rectorIgnorePaths']`, which silently changes the skip list if the variable is set. Suggested fix: per-case globs, and keep unmatched-ignore reporting on.

**10. `install.sh` symlink replacement (Low).** The guard at `install.sh:123` protects only regular files, so an existing symlink to a real `jq` is overwritten by `ln -sf`. The header comment says links exist so they do not shadow a real `jq`. Suggested fix: refuse any existing target that is not already a link to phpxq.

**11. CI duplication and stale comment (Low).** `qa.yml:144-167` and `:303-326` are identical, and `:169-227` and `:328-387` are identical. The header comment at `:77-79` says tests are deferred, but the gate runs PHPUnit and the shell conformance (`:422-428`). Suggested fix: a reusable workflow or composite action, and update the comment.

**12–15 (Info).** The backup `.CLAUDE.md.pre-inject` is byte-identical to `CLAUDE.md`; it is gitignored, so it can be deleted. Benchmark baselines are committed; they age and may not belong in history. `FactorySealedBy.php` is a generated file that ships in the PHAR. `Xdebug.php:38` calls `pcntl_exec` through a string callable. The 16 KB-per-call fiber stack reservation is a speculative concern.

### Verified positives

- `gap-aware` conformance fails on a known gap that starts passing, so the list cannot silently go stale.
- Downloads in packaging are SHA-256 verified, and upstream fixture refresh pins tags and commits.
- `release.yml` refuses to run from any ref other than `release`, and the release QA job runs the full `vendor/bin/qa`.
- `install.sh` verifies the checksum before installing anything.
- JSON nesting at 200,000 levels fails cleanly with exit 5; at 9,000 it succeeds.
- The ReDoS probe (`(a+)+$` on 40 characters) fails in 0.27s with the PCRE backtrack limit.
- `@sh` quoting is correct.
- `repeat` string growth is capped.
- The module loader rejects `..` components and resolves real paths.
- The YAML scanner's token buffer compacts amortised O(1).
- The PHPStan configuration is at level max, with the strict and deprecation rule sets installed and no baseline file.

### Not checked

I did not run the conformance report, the full pipeline, or the PHP unit suite, so I could not confirm the README's gap counts or the current test pass rate. I did not review the `.claude/` tooling or most of `src/Jq/Runtime/Eval/`. The 3-level and 6-level YAML tests, the regex and slicing timings, and the traversal probe were all run against `bin/phpxq` via a symlink in `untracked/scratch/`.