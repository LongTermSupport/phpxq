# `phpxq.unguardedAliasRecursion` - alias-following recursion with no depth bound

**Rule**: `UnguardedAliasRecursionRule` (`qaConfig/PHPStan/Rules/`, registered in `qaConfig/phpstan.neon`)

## What fires

A recursive method that resolves a YAML alias on the way down (`NodeOps::deref()`,
`NodeTools::unwrap()` or a read of `aliasTarget`) and recurses into what it found, when neither the
method nor any method on the same recursion cycle compares an `int` parameter named `depth`, `nesting`,
`remaining` or `budget` with a named constant:

```php
public static function canonical(Node $node): string
{
    $node = NodeOps::deref($node);
    foreach ($node->content as $item) {
        $parts[] = self::canonical($item);   // follows aliases, never stops on `&a [*a]`
    }
    // ...
}
```

## Why it is a hazard

An alias points at the node carrying its anchor, and the anchor may sit above the alias:
`d: &y [*y, 2]`. Walking `content` alone is a walk over a tree and ends. Following aliases while
descending is a walk over a graph that can contain a cycle, so the recursion never ends: the process
spins until memory or time runs out (`yq '.d | unique'` hung before the bound existed).

## The correct construction

Take an `int $depth = 0`, compare it with a `MAX_DEPTH` constant before doing anything else, and fail:

```php
if ($depth > self::MAX_DEPTH) {
    throw new EvaluationException('exceeded max depth (alias cycle?)');
}
```

Pass `$depth + 1` on every recursive call. `Anchors`, the format encoders and `Compare` follow this shape.
A comparison with a literal (`0 !== $depth`) is a user-visible limit, not a bound on a cycle, and does not
count.

## What is not reported

- A recursive call that also descends a parameter never resolved through an alias, such as merging into a
  caller-owned tree: the tree is finite, so the recursion ends with it.
- Tree walks over `content` that read `aliasTarget` without recursing into it.

## Limits

Only recursion inside one class is seen. The check that a cycle is bounded accepts the guard in any method
of the cycle and does not verify that every member passes the depth along.
