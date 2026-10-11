# `phpxq.staticOnlyClassConstructor` - static-only class with no constructor guard

**Rule**: `StaticOnlyClassConstructorRule` (`qaConfig/PHPStan/Rules/`, registered in `qaConfig/phpstan.neon`)

## What fires

A named, concrete class that has at least one static method, static property or constant, no instance method
or property, and no constructor:

```php
final class FlagCatalog
{
    public static function names(): array { /* ... */ }
}

new FlagCatalog();   // compiles, runs, and builds an object with no state and no behaviour
```

## Why it is a hazard

A class of static members is a namespace for functions, not a type. Nothing stops `new FlagCatalog()`, and a stray
instantiation (a forgotten `::`, a mistaken dependency injection) is silently accepted. A private constructor
turns the mistake into an error where it is made.

## The correct construction

Declare the guard the other static-only classes in `src/` carry:

```php
private function __construct()
{
}
```

## What is not reported

- A class with a constructor of any visibility, since instantiation is then the author's decision.
- A class with an instance method or property: it is instantiable on purpose.
- Abstract and anonymous classes, interfaces, traits and enums (an enum cannot declare a constructor).
- A class that uses a trait or extends a parent: either can supply instance members or the constructor.
- A class with no members.

## Limits

Only the class body is read. A trait's or parent's members are not followed, so a static-only class that extends
a parent or uses a trait is skipped rather than judged.
