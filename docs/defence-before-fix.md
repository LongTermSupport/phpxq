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

| Class                                                  | Identifier                                                                                        | What it catches                                                                                                                                 | Run it                                                                                                           |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Recursion that exhausts the native stack               | `phpxq.recursionThroughNativeCallback`                                                            | A method that recurses through a closure given to a native function (`array_all`, `array_map`, `usort`, ...)                                    | `vendor/bin/phpstan-rule phpxq.recursionThroughNativeCallback src`                                               |
| Recursion over a graph that can contain a cycle        | `phpxq.unguardedAliasRecursion`                                                                   | Recursion into a node reached through a YAML alias or merge key, directly or through an own helper, with no depth bound                         | `vendor/bin/phpstan-rule phpxq.unguardedAliasRecursion src`                                                      |
| Expensive object rebuilt per iteration                 | `phpxq.loopInvariantConstruction`                                                                 | `new Registry/Compiler/Parser/Encoder/...(...)` with unchanging arguments in a loop, or a call to a helper that builds one without keeping it   | `vendor/bin/phpstan-rule phpxq.loopInvariantConstruction src tests`                                              |
| Static-only class that can be instantiated             | `phpxq.staticOnlyClassConstructor`                                                                | A class of only static members and constants with no constructor: add `private function __construct()`                                          | `vendor/bin/phpstan-rule phpxq.staticOnlyClassConstructor src tests`                                             |
| Swallowed errors                                       | `phpqaci.silentCatch`                                                                             | A catch block that neither uses, logs nor rethrows the exception (rule bundled with php-qa-ci, enabled here)                                    | `vendor/bin/phpstan-rule phpqaci.silentCatch src`                                                                |
| Shared structure versus deep copy in the yq Node model | no static rule                                                                                    | Property-style tests over generated documents: an update through `..`, `\|=` and `+=` must reach every node at every depth                      | `vendor/bin/phpunit -c qaConfig/phpunit.xml --no-coverage tests/Unit/Yq/Runtime/SharedStructurePropertyTest.php` |
| Closed set of names written as strings                 | `phpxq.stringDiscriminator`                                                                       | A value told apart by comparing it with two or more name literals (`===`, `in_array`, `match`, `switch`): it is an enum that was never declared | `vendor/bin/phpstan-rule phpxq.stringDiscriminator src`                                                          |
| Same string literal written three or more times        | `phpqaci.repeatedStringLiteral`                                                                   | An undeclared constant (production code only; tests state one expectation per assertion)                                                        | `vendor/bin/phpstan-rule phpqaci.repeatedStringLiteral src`                                                      |
| Docblock literal union                                 | `phpqaci.enumOverLiteralUnion`                                                                    | `@param 'a'\|'b'`: a backed enum that was never declared                                                                                        | `vendor/bin/phpstan-rule phpqaci.enumOverLiteralUnion src tests`                                                 |
| Mutable services                                       | `phpqaci.readonlyService`                                                                         | A service class that is not `final readonly`; per-call state belongs on the stack or in a state object                                          | `vendor/bin/phpstan-rule phpqaci.readonlyService src tests`                                                      |
| Hidden nulls                                           | `phpqaci.nullCoalescingEmptyString`, `phpqaci.nullCoalescingFalse`                                | `?? ''` and `?? false` turn a missing value into a plausible one                                                                                | `vendor/bin/phpstan-rule phpqaci.nullCoalescingEmptyString src tests`                                            |
| Docblock-only list parameters and ambiguous arrays     | `phpqaci.variadicOverArrayParameter`, `phpqaci.ambiguousArrayDoc`, `phpqaci.consistentMemberDocs` | A last `list<T>` parameter that should be `T ...$x`; `T[]` that states no keys; half-documented members                                         | `vendor/bin/phpstan-rule phpqaci.variadicOverArrayParameter src tests`                                           |
| Insecure and debug functions                           | `phpqaci.insecureFunction`, `phpqaci.debugOutputFunction`                                         | `md5`/`sha1`/`mt_rand`, and `var_dump`/`print_r` that print                                                                                     | `vendor/bin/phpstan-rule phpqaci.insecureFunction src tests`                                                     |
| Copy and paste                                         | `phpcpd` (php-qa-ci tool)                                                                         | Duplicated blocks across files; extract a shared method or class                                                                                | `vendor/bin/qa -t phpcpd`                                                                                        |

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
tree walk into a graph walk, which never ends on `&a [*a]`. A merge key that merges its own mapping
(`a: &a {x: 1, <<: *a}`) is the same class: the alias was resolved inside a private helper that returns the
merge sources, which the rule first missed, and the JSON encoder and `explode` segfaulted. The rule now
treats an own method whose return value comes from an alias as a resolver, transitively, and knows the
merge-source resolver. The sweep (a search for every consumer of merge sources, and a reading of every own
method that unwraps an alias) found the two instances the widened rule reports and no others. A blanket "recursive function without a depth
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

**Magic strings and duplication.** The tool names are one backed enum (`ToolEnum`: `jq`, `yq`); format
names, comment kinds, shells, builtin names and similar closed sets are enums or class constants.
`phpxq.stringDiscriminator` and the bundled repeated-literal and literal-union rules hold the line. The
only allowances are written down: test code is exempt from the two string rules (data repeats on purpose),
and the namespaces listed under `StringDiscriminatorRule` in `qaConfig/phpstan.neon` are lexers, parsers
and regex translators whose literals are the grammar of an external text syntax
(`docs/phpstan-rules/string-discriminator.md`). A rule that crashes on odd syntax would abort the whole
analysis, so `tests/Unit/QaConfig/PHPStan/Rules/RulesNeverCrashTest.php` runs every project rule over every
file in `src/` and `tests/`, and the fixtures include first-class callable syntax (`foo(...)`).

**Shared structure (the engine fix).** The property net was red against the engine: `X |= [] + .` copied
the children of the node it was replacing, so an enclosing `..` kept matches that were no longer in the
document. Arithmetic now takes its children from the node an update replaces (`EvaluationContext::replacedNode`,
`ArithmeticOperator::apply`) and still copies anything that comes from elsewhere in the document.

## Honest limits

- The shared-structure class has no static rule; the property net checks the update operators it generates
  and no others.
- The two `phpxq` recursion rules see one class at a time, and the loop rule recognises engines by class-name
  ending. `phpqaci.readonlyService` and the string rules decide by shape, so a stateful class that is not
  named like a service is simply not seen.
- `phpcpd` is advisory in the pipeline (it exits 0); clones are removed by hand when it lists them. Seven
  remain (121 duplicated lines), kept on purpose: value-mode and path-mode twins in `ForeachOp`/`ReduceOp`,
  the `base32` encode and decode pair, two `match` tables in `FormatRegistry`, and data-provider rows in three
  tests. Extracting them would need abstractions harder to read than the repetition.
- Under Xdebug in coverage mode, which `vendor/bin/qa` forces, every PHP call uses the native stack and the
  `deep recursion` case of `CompilerTest` (a jq function recursing 10,000 levels) overflows the default 8 MB
  stack. The unmodified main branch does the same, so the full gate here runs under `ulimit -s 1048576`.
  A recursion-depth guard in the jq evaluator would remove the dependency; it is not in this change.
- Rector's `ForeachToArrayAll`/`ForeachToArrayAny` rules rewrite a `foreach` loop into a native
  callback, which is the hazard `phpxq.recursionThroughNativeCallback` reports. The two recursive loops that
  matter (`Compare::deepEquals`, `SelectionCalls::containsNode`) are written as `for` loops, which Rector
  leaves alone.
- Variadic parameters surface as `array<int|string, T>` to PHPStan, so passing one on as a list needs
  `array_values()`.

## Adding a defence

Name the class and its hazard, search for it by two independent techniques, then write the rule in
`qaConfig/PHPStan/Rules/`, a flagged and a clean fixture in `tests/Fixtures/Defence/`, and a
`RuleTestCase` in `tests/Unit/QaConfig/PHPStan/Rules/`. Prove it with `vendor/bin/phpstan-rule`, commit the
defence with the instances still present, fix every instance in later commits, register the rule in
`qaConfig/phpstan.neon` and add its row to `docs/phpstan-rules/README.md` with a remediation page.
