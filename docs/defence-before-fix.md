# Defence before fix

phpxq follows [Defence Before Fix](https://defence-before-fix.github.io/): a defect is evidence of a
class, so the class gets a detector before the instance gets a fix. The detector is a rule in a tool that
reads code (a custom PHPStan rule, here) and is proven by making it fire on fixture code and on the
originating defect, then run over the whole codebase to count every instance. Only then is each instance
fixed, with a regression test that reproduces it. Static analysis is the net that catches the class on
every future commit; the test is the filter that proves the specific fix.

The rules fail the build instead of warning: they are registered in `qaConfig/phpstan.neon`, which the
php-qa-ci pipeline runs on `src/` and `tests/`. A suppression, a baseline or a narrowing of a rule is a
decision for the project owner and is not made in code. The method as applied by the toolchain is in
`vendor/lts/php-qa-ci/CLAUDE/DefenceBeforeFix.md`.

## The defences

| Class                                                  | Identifier                             | What it catches                                                                                                                               | Run it                                                                                                           |
| ------------------------------------------------------ | -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Recursion that exhausts the native stack               | `phpxq.recursionThroughNativeCallback` | A method that recurses through a closure given to a native function (`array_all`, `array_map`, `usort`, ...)                                  | `vendor/bin/phpstan-rule phpxq.recursionThroughNativeCallback src`                                               |
| Recursion over a graph that can contain a cycle        | `phpxq.unguardedAliasRecursion`        | Recursion into a node reached through a YAML alias (`NodeOps::deref`, `NodeTools::unwrap`, `aliasTarget`) with no depth bound                 | `vendor/bin/phpstan-rule phpxq.unguardedAliasRecursion src`                                                      |
| Expensive object rebuilt per iteration                 | `phpxq.loopInvariantConstruction`      | `new Registry/Compiler/Parser/Encoder/...(...)` with unchanging arguments in a loop, or a call to a helper that builds one without keeping it | `vendor/bin/phpstan-rule phpxq.loopInvariantConstruction src tests`                                              |
| Swallowed errors                                       | `phpqaci.silentCatch`                  | A catch block that neither uses, logs nor rethrows the exception (rule bundled with php-qa-ci, enabled here)                                  | `vendor/bin/phpstan-rule phpqaci.silentCatch src`                                                                |
| Shared structure versus deep copy in the yq Node model | no static rule                         | Property-style tests over generated documents: an update through `..`, `\|=` and `+=` must reach every node at every depth                    | `vendor/bin/phpunit -c qaConfig/phpunit.xml --no-coverage tests/Unit/Yq/Runtime/SharedStructurePropertyTest.php` |

`vendor/bin/rule-doc <identifier>` prints the rule class, its summary and the remediation page, offline.
The index is `docs/phpstan-rules/README.md`, declared in `qaConfig/rule-docs.json`. The rules and their
call-graph helpers live in `qaConfig/PHPStan/Rules/`; each has a rule test in
`tests/Unit/QaConfig/PHPStan/Rules/` that runs PHPStan over the fixtures in `tests/Fixtures/Defence/`
(a flagged fixture and a clean fixture per rule). The fixtures are excluded from the project's own
analysis and fixers, because each one is deliberately an instance of its class.

## Why these classes, and what each rule does not see

**Recursion.** PHP calls between userland functions use heap frames, so a deeply nested document does not
exhaust the stack by itself (a 5,000,000 level recursion runs). Two hazards remain and each has a rule. A
callback entered from a native function uses the C stack and dies after a few thousand levels (the
`array_all` in `Compare::deepEquals` died on a cyclic alias). Following aliases while descending turns a
tree walk into a graph walk, which never ends on `&a [*a]`. A blanket "recursive function without a depth
parameter" rule was rejected: jq values are trees, recursion over them is bounded by memory, and the rule
would report every parser and evaluator. Limits: only recursion inside one class is seen; the cycle bound
is accepted anywhere on the cycle; a comparison against a named constant is what counts as a bound.

**Per-iteration construction.** One test rebuilt a builtin registry 24,000 times through two helper
methods. The rule follows own-class helpers that build an engine on every call (straight-line code only,
nothing behind a branch or a loop) and reports calls to them inside loops and native iteration callbacks,
plus direct `new` with unchanging arguments. Limits: engines are recognised by class-name ending, helpers
are followed inside one class only, and a data-provider test that builds an engine per case is not seen.

**Deep copy versus sharing.** `Node::deepCopy()` is called about seventy times in `src/Yq/Runtime`, nearly
all rightly; whether a copy orphans a match depends on what a later step holds, which a rule cannot see.
Flagging every call would be noise and an allow-list of reviewed calls would only record that someone
looked. The defence is the property-style test net named in the table, which asserts the semantics (every
node at every depth is updated) rather than the mechanism.

**Swallowed errors.** The bundled `phpqaci.silentCatch` rule is enabled instead of a project rule, because
it already states the class precisely.

## Adding a defence

Name the class and its hazard, search for it by two independent techniques, then write the rule in
`qaConfig/PHPStan/Rules/`, a flagged and a clean fixture in `tests/Fixtures/Defence/`, and a
`RuleTestCase` in `tests/Unit/QaConfig/PHPStan/Rules/`. Prove it with `vendor/bin/phpstan-rule`, commit the
defence with the instances still present, fix every instance in later commits, register the rule in
`qaConfig/phpstan.neon` and add its row to `docs/phpstan-rules/README.md` with a remediation page.
