# Project rule identifier index

The identifiers printed by this project's own static defences, and where each is documented. The
lookup works offline:

```bash
vendor/bin/rule-doc phpxq.recursionThroughNativeCallback
vendor/bin/phpstan-rule phpxq.recursionThroughNativeCallback src
```

The index is declared in `qaConfig/rule-docs.json`. Identifiers prefixed `phpqaci.` belong to the
php-qa-ci bundle and resolve from its own index.

| Identifier                             | Rule class                           | Forbids                                                                                                            | Page                                                                         |
| -------------------------------------- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------- |
| `phpxq.recursionThroughNativeCallback` | `RecursionThroughNativeCallbackRule` | A recursive method whose recursive call sits in a callback given to a native function                              | [recursion-through-native-callback.md](recursion-through-native-callback.md) |
| `phpxq.unguardedAliasRecursion`        | `UnguardedAliasRecursionRule`        | Recursion into a node reached through a YAML alias with no compared depth bound                                    | [unguarded-alias-recursion.md](unguarded-alias-recursion.md)                 |
| `phpxq.loopInvariantConstruction`      | `LoopInvariantConstructionRule`      | An engine object (registry, compiler, parser, encoder) built with unchanging arguments on every pass of a loop     | [loop-invariant-construction.md](loop-invariant-construction.md)             |
| `phpxq.stringDiscriminator`            | `StringDiscriminatorRule`            | A variable or property told apart by comparing it with two or more different name literals (closed set as strings) | [string-discriminator.md](string-discriminator.md)                           |
| `phpxq.staticOnlyClassConstructor`     | `StaticOnlyClassConstructorRule`     | A class of only static members with no constructor, so `new Helper()` builds a useless instance                    | [static-only-class-constructor.md](static-only-class-constructor.md)         |

## Production-only scope

`phpqaci.repeatedStringLiteral` (the same string literal written three or more times in one class)
is registered through `QaConfig\PHPStan\Rules\ProductionOnlyRule`, which hands a file to the
php-qa-ci rule only when it is outside `tests/`. The rule's node type, message and identifier are
unchanged, so a finding reads as if the rule were registered directly.

The hazard the rule defends against, a value with no single definition whose copies drift apart,
cannot arise in a test: test data states one expectation per assertion on purpose (a builtin name
repeated per case), and each copy is checked by the assertion beside it, so a shared constant would
only hide which case an assertion covers. Production code under `src/` is held to the rule in full;
there is no baseline and no ignore entry. The wrapper is proved by
`tests/Unit/QaConfig/PHPStan/Rules/ProductionOnlyRuleTest.php`.
