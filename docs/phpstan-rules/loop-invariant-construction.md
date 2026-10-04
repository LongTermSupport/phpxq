# `phpxq.loopInvariantConstruction` - an engine object rebuilt on every pass of a loop

**Rule**: `LoopInvariantConstructionRule` (`qaConfig/PHPStan/Rules/`, registered in `qaConfig/phpstan.neon`)

## What fires

Inside a loop body (`for`, `foreach`, `while`, `do`) or a callback given to a native iteration function
(`array_map`, `array_filter`, `usort`, ...), either of:

- `new Engine(...)` whose arguments are constants, `$this` properties or variables the loop never writes;
- a call to a method of the same class that builds an engine on every call, without keeping it.

An engine is a class whose name ends in `Registry`, `Catalog`, `Compiler`, `Factory`, `Parser`, `Lexer`,
`Scanner`, `Encoder`, `Decoder`, `Emitter`, `Evaluator` or `Loader`.

```php
for ($day = 0; $day < 24000; ++$day) {
    $this->call($day);                     // call() -> registry() -> new DefaultBuiltinRegistry()
}
```

## Why it is a hazard

A builtin registry registers hundreds of functions; building it 24,000 times made one unit test take
minutes. Nothing fails, so the cost is invisible until a loop grows. The helper shape hides it from a
reader and from a text search: the loop contains no `new`.

## The correct construction

Build once, before the loop, or keep the instance:

```php
private function registry(): DefaultBuiltinRegistry
{
    return $this->registry ??= new DefaultBuiltinRegistry();
}
```

## What is not reported

- Construction that depends on the iteration (`new StreamParser($line)`).
- Construction behind a branch or a loop of its own inside a helper: the helper then does not build on
  every call.
- Construction that is kept: assigned to a property, a static property or a `??=` slot, or inside a method
  that declares a `static` variable.
- A `new` under a `return` inside the loop: that pass leaves the loop.
- Classes whose names do not end in an engine word.

## Limits

The engine list is a naming convention. A class with an expensive constructor and another name is not
seen, and a stateful engine that must be fresh per iteration is accepted when its arguments depend on the
iteration. Helpers are followed inside one class only.
