# Opus vs Haiku review comparison

Same brief, both read-only. Opus 5.5 (high effort) coordinated three Opus sub-reviewers and wrote its own report. Haiku 5.5 worked alone and, citing a rule against report files, returned its report as a message; the coordinator saved it unedited.

## Size

| Measure           | Opus                                   | Haiku                              |
| ----------------- | -------------------------------------- | ---------------------------------- |
| Findings          | 3 critical, 10 high, about 30 lower    | 2 high, 5 medium, 8 low/info       |
| Reproduced        | Most runtime findings, headlines twice | Timings and three probes           |
| Areas not covered | none declared                          | `Eval/`, `.claude/`, full test run |

## Overlap (both found)

- Autofix job in `qa.yml` runs with a write token and unpinned actions
- Quadratic UTF-8 handling (Haiku: `Text::slice`; Opus: `indices`, scanner `colAt`, others)
- No alias-expansion budget in YAML output
- `--split-exp` writes to data-derived paths
- Sparse index allocation cap
- PHPUnit does not fail on skipped, deprecation or notice
- Duplicated CI blocks, stale comments
- `install.sh` symlink replacement

## Only Opus

- YAML merge-key self-cycle segfaults PHP (exit 139), the most severe defect found
- Merge-key exponential fan-out; parser nesting segfaults, including via yq `eval` on data
- Invalid UTF-8 from `--arg` gives an uncatchable internal error
- `until`/`while` fail after 20000 iterations
- 70% of Infection mutants skipped, so the 90% mutation floor is mostly hollow
- The project's own `UnguardedAliasRecursionRule` misses the merge-key recursion
- XML encoder markup injection, yq regex errors silently returning wrong answers, Xdebug re-exec dropping `-d` settings
- Missing `#[CoversClass]` on 218 test files, PHPStan bleedingEdge and opt-in checks
- Release signing and provenance, fork-PR gate behaviour, RELEASING.md contradiction
- Six more quadratic hot spots

## Only Haiku

- Regex `l` flag is O(n²) (Opus did not list it)
- Whole-file globs in the yq known-gaps list and a README count mismatch (Opus judged the entries specific; the globs are real, so Opus under-reported here)
- `composer-dependency-analyser` unmatched-ignore reporting disabled
- Committed benchmark snapshots and `call_user_func('pcntl_exec')` as info items

## Accuracy

- Haiku's mutation-floor finding (Infection skipped when Xdebug is off in CI) and Opus's (70% of mutants skipped locally) are different defects of the same gate; together they show the floor measures little.
- Haiku's `--split-exp` and alias findings are correct but less severe than Opus's, which found the crash and hang forms.
- Neither report contained a finding the other disproved. Items to verify by reproduction before fixing are tracked in the plan journal.

## Verdict

Opus found the defects that matter most (crashes, hangs, a hollow QA gate) and proposed the defence-before-fix rule gap. Haiku was fast, accurate on what it covered and caught a few things Opus missed, but stayed on the surface of the evaluator and missed every crash-class finding. Use Haiku as a cheap second pass, not a replacement.
