# Upstream proposal: diff mode as the php-qa-ci default on non-default branches

php-qa-ci already ships an opt-in diff mode for the Infection lane (`QaConfigBuilder::withInfectionDiffBase()`,
`Lane/InfectionTool.php`, `Lane/Infection/InfectionDiffFilter.php`). phpxq keeps its own scoping
(`scripts/mutation-scope.bash`, `scripts/Qa/*`, `scripts/check-qa-measurements.bash`) because the built-in loses
rigour in the ways below. This is what php-qa-ci needs to gain for projects to drop their copies.

## Gap table

| Behaviour                       | phpxq scoping                                                                        | php-qa-ci diff mode                                                         |
| ------------------------------- | ------------------------------------------------------------------------------------ | --------------------------------------------------------------------------- |
| Renamed or copied source        | mutated (`-M`, status R/C)                                                           | dropped: `--diff-filter=AM` excludes R, so a moved file is never mutated    |
| Changed or deleted test         | maps to the source it mirrors (file, else directory, else nearest parent)            | ignored: a weakened test mutates nothing                                    |
| Test or QA config change        | `composer.json`, `qaConfig/phpunit.xml`, `qaConfig/qa.php`, test support: mutate all | ignored                                                                     |
| Change that maps to no source   | scope `none`: lane off, measurement check accepts no summary                         | empty diff skips (equivalent), but only for src/ and no mapping             |
| Unparseable or unplaceable diff | mutate everything (fail safe)                                                        | `git diff` failure fails; no widening                                       |
| Verification of what ran        | `--verify` recomputes the scope; the override must be exactly what scope writes      | none: nothing proves the mutated set matches the diff                       |
| Summary log                     | full-mode `--log-verbosity=all`, read for the skipped-mutant cap and MSI             | diff args omit the log, so no `summary-log.txt`; skip cap cannot be applied |
| Floors                          | MSI and covered MSI both enforced (88/88)                                            | covered MSI only; plain MSI not checked                                     |
| Base choice                     | per workflow: default branch, previous push commit, previous release tag             | one configured ref; no default-branch or tag logic                          |
| Run on the default branch       | push base is the commit before the push                                              | opt-in only; nothing says "off on main"                                     |
| Uncommitted work                | scope is committed history; local edits do not block                                 | refuses a dirty `src/`/`tests/` tree (reproducible, but blocks iteration)   |
| Config for narrowed run         | generated `qaConfig/infection.json` excludes the rest                                | positional absolute paths                                                   |
| Scoped run with no mutants      | passes, says so                                                                      | n/a                                                                         |

What diff mode does that phpxq does not: positional paths avoid generating a config file, and a dirty-tree refusal
makes the verdict reproducible.

## Proposed change

1. Default on: when the project does not set a base and the current branch is not the default branch, diff mode
   uses the merge base with the default branch (`origin/HEAD`, else `origin/main`, else `origin/master`);
   on the default branch it stays full. `withInfectionDiffBase(null)` or `infectionDiffBase=` forces full.
2. Scope rules as a small value object with an overridable mapping, not a hard-coded one:
   `withInfectionScopeRules(sourceDir: 'src', testMirrors: ['tests/Unit' => 'src'], mutateAllOn: ['composer.json', ...], mutateNothingUnder: [...])`.
   Default rules: changed test maps to the mirrored source, else nearest directory; unplaceable path mutates all.
3. Diff the committed range with `-M` and `--diff-filter=AMRC`, reading `-z --name-status` so odd paths survive.
4. Always write the Infection summary log in diff mode, plus a machine-readable scope file
   (`var/qa/infection/scope.json`: kind, base, files) so a project check can verify the run matched the scope.
5. Enforce plain MSI as well as covered MSI in diff mode when the changed files hold enough mutants
   (`withInfectionDiffFloors(msi, coveredMsi)`), else covered MSI only.
6. Optional skipped-mutant cap in the lane itself: `withInfectionMaxSkippedPercent(int)`; fail when exceeded.
7. A `dirty tree` policy setting: `refuse` (today) or `committed-only` (diff committed history, ignore the rest).

## Tests it needs

- `InfectionDiffFilter`: rename is kept; copy kept; deleted file dropped; path with space, tab and non-ASCII byte;
  non-PHP file ignored.
- Scope rules: each row of the gap table (test to source, test to directory, nearest parent, top-level test with no
  source mirror mutates all, config file mutates all, docs only gives none).
- `InfectionTool`: default base resolved on a feature branch and none on the default branch; summary log written in
  diff mode; scope file matches the positional paths; an empty scope skips; a failing `git diff` fails.
- `QaConfigBuilder`: floors below 100 for the diff floors, the skip cap range, and an explicit null forcing full mode.
- Mutation testing of the scope-rule class itself, at the floor the project holds.
