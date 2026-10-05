# `phpxq.recursionThroughNativeCallback` - recursion through a callback given to a native function

**Rule**: `RecursionThroughNativeCallbackRule` (`qaConfig/PHPStan/Rules/`, registered in `qaConfig/phpstan.neon`)

## What fires

A method that reaches itself, directly or through its sibling methods, where the recursive call sits
inside a closure, arrow function or first-class callable handed to a native function (`array_map`,
`array_filter`, `array_all`, `array_any`, `usort`, `preg_replace_callback`, ...):

```php
private static function deepEquals(Node $left, Node $right): bool
{
    // one native stack frame per level of nesting
    return array_all($left->content, static fn (Node $item, int $i): bool => self::deepEquals($item, $right->content[$i]));
}
```

## Why it is a hazard

Calls between PHP functions use the VM's own heap-allocated frames, so plain recursion is bounded by
memory and survives hundreds of thousands of levels. A callback invoked by a native function is entered
from C instead, and each level then keeps a native stack frame. The C stack is exhausted after a few
thousand levels with `Maximum call stack size ... reached`, which the front controller reports as an
internal error. The same recursion written as a loop survives the same input, so the defect shows only on
deeply nested or cyclic documents that tests rarely contain.

## The correct construction

Walk with an indexed `for` or a `foreach`, which keeps the recursion in PHP frames:

```php
$count = \count($left->content);
for ($i = 0; $i < $count; ++$i) {
    if (!self::deepEquals($left->content[$i], $right->content[$i], $depth + 1)) {
        return false;
    }
}
```

`TypeFunctions::anyContains()` in `src/Jq/Builtin/Core/TypeFunctions.php` documents the same choice.

## What is not reported

A callback that calls a recursive method from a method that is not itself on the cycle: the callback
returns before the recursion deepens, so no native frame stays on the stack.

## Limits

Only recursion inside one class is seen. Recursion that crosses classes, or that reaches a native
function through a variable holding a callable, is not detected.
