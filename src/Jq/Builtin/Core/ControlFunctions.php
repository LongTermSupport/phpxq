<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use ArrayIterator;
use Closure;
use InfiniteIterator;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\Filter;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\Values;

/**
 * Generators and control: empty, error, select, map, range, limit, first, last, skip, isempty, any, all,
 * recurse and repeat. Those valid in a path expression (`path(first(.a, .b))`) are path-aware.
 *
 * @internal
 */
final class ControlFunctions
{
    private function __construct()
    {
    }

    public static function register(BuiltinRegistry $registry): void
    {
        $registry->register(new PathStreamFunction('empty', 0, self::nothing(...), self::nothingPaths(...)));
        $registry->register(new PathStreamFunction('error', 0, self::error0(...), self::error0Paths(...)));
        $registry->register(new PathStreamFunction('error', 1, self::error1(...), self::error1Paths(...)));
        $registry->register(new PathStreamFunction('select', 1, self::select(...), self::selectPaths(...)));
        $registry->register(new StreamFunction('map', 1, self::map(...)));
        $registry->register(new PathStreamFunction('first', 1, self::first(...), self::firstPaths(...)));
        $registry->register(new PathStreamFunction('limit', 2, self::limit(...), self::limitPaths(...)));
        $registry->register(new PathStreamFunction('skip', 2, self::skip(...), self::skipPaths(...)));
        $registry->register(new StreamFunction('last', 1, self::last(...)));
        $registry->register(new StreamFunction('isempty', 1, self::isEmpty(...)));
        $registry->register(new StreamFunction('any', 2, self::any(...)));
        $registry->register(new StreamFunction('all', 2, self::all(...)));
        $registry->register(new StreamFunction('range', 1, self::range1(...)));
        $registry->register(new StreamFunction('range', 2, self::range2(...)));
        $registry->register(new StreamFunction('range', 3, self::range3(...)));
        $registry->register(new PathStreamFunction('recurse', 0, self::recurse0(...), self::recurse0Paths(...)));
        $registry->register(new PathStreamFunction('recurse', 1, self::recurse1(...), self::recurse1Paths(...)));
        $registry->register(new PathStreamFunction('recurse', 2, self::recurse2(...), self::recurse2Paths(...)));
        $registry->register(new StreamFunction('repeat', 1, self::repeat(...)));
    }

    private static function nothing(): void
    {
    }

    private static function nothingPaths(): void
    {
    }

    private static function error0(RuntimeContext $c, mixed $input): never
    {
        throw new JqException($input);
    }

    private static function error0Paths(RuntimeContext $c, mixed $path, mixed $input): never
    {
        throw new JqException($input);
    }

    /**
     * @param list<Filter> $args
     */
    private static function error1(RuntimeContext $c, mixed $input, array $args): void
    {
        $args[0]->run($input, static function (mixed $message): never {
            throw new JqException($message);
        });
    }

    /**
     * @param ?list<mixed> $path
     * @param list<Filter> $args
     */
    private static function error1Paths(RuntimeContext $c, ?array $path, mixed $input, array $args): void
    {
        self::error1($c, $input, $args);
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function select(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $condition) use ($input, $emit): void {
            if (null !== $condition && false !== $condition) {
                $emit($input);
            }
        });
    }

    /**
     * @param ?list<mixed>                       $path
     * @param list<Filter>                       $args
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function selectPaths(RuntimeContext $c, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $condition) use ($path, $input, $emit): void {
            if (null !== $condition && false !== $condition) {
                $emit($path, $input);
            }
        });
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function map(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $items = $input;
        if ($items instanceof JsonObject) {
            $items = $items->values();
        }

        if (!\is_array($items)) {
            throw Problems::iterate($input);
        }

        $out     = [];
        $collect = static function (mixed $value) use (&$out): void {
            $out[] = $value;
        };
        $filter = $args[0];
        foreach ($items as $item) {
            $filter->run($item, $collect);
        }

        $emit($out);
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function first(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        EarlyExit::run(static function (object $label) use ($input, $args, $emit): void {
            $args[0]->run($input, static function (mixed $value) use ($emit, $label): never {
                $emit($value);
                EarlyExit::stop($label);
            });
        });
    }

    /**
     * @param ?list<mixed>                       $path
     * @param list<Filter>                       $args
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function firstPaths(RuntimeContext $c, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        EarlyExit::run(static function (object $label) use ($path, $input, $args, $emit): void {
            $args[0]->paths($path, $input, static function (?array $where, mixed $value) use ($emit, $label): never {
                $emit($where, $value);
                EarlyExit::stop($label);
            });
        });
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function limit(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $count) use ($input, $args, $emit): void {
            $wanted = self::count($count, "limit doesn't support negative count");
            if (0.0 === $wanted) {
                return;
            }

            $seen = 0;
            EarlyExit::run(static function (object $label) use ($input, $args, $emit, &$seen, $wanted): void {
                $args[1]->run($input, static function (mixed $value) use ($emit, $label, &$seen, $wanted): void {
                    ++$seen;
                    $emit($value);
                    if ($seen >= $wanted) {
                        EarlyExit::stop($label);
                    }
                });
            });
        });
    }

    /**
     * @param ?list<mixed>                       $path
     * @param list<Filter>                       $args
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function limitPaths(RuntimeContext $c, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $count) use ($path, $input, $args, $emit): void {
            $wanted = self::count($count, "limit doesn't support negative count");
            if (0.0 === $wanted) {
                return;
            }

            $seen = 0;
            EarlyExit::run(static function (object $label) use ($path, $input, $args, $emit, &$seen, $wanted): void {
                $args[1]->paths($path, $input, static function (?array $where, mixed $value) use ($emit, $label, &$seen, $wanted): void {
                    ++$seen;
                    $emit($where, $value);
                    if ($seen >= $wanted) {
                        EarlyExit::stop($label);
                    }
                });
            });
        });
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function skip(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $count) use ($input, $args, $emit): void {
            $skipping = self::count($count, "skip doesn't support negative count");
            $seen     = 0;
            $args[1]->run($input, static function (mixed $value) use ($emit, &$seen, $skipping): void {
                if ($seen < $skipping) {
                    ++$seen;

                    return;
                }

                $emit($value);
            });
        });
    }

    /**
     * @param ?list<mixed>                       $path
     * @param list<Filter>                       $args
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function skipPaths(RuntimeContext $c, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $count) use ($path, $input, $args, $emit): void {
            $skipping = self::count($count, "skip doesn't support negative count");
            $seen     = 0;
            $args[1]->paths($path, $input, static function (?array $where, mixed $value) use ($emit, &$seen, $skipping): void {
                if ($seen < $skipping) {
                    ++$seen;

                    return;
                }

                $emit($where, $value);
            });
        });
    }

    private static function count(mixed $count, string $negative): float
    {
        if (!Num::isNumber($count)) {
            throw new JqException($negative);
        }

        $number = Num::toFloat($count);
        if ($number < 0) {
            throw new JqException($negative);
        }

        return $number;
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function last(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $found = false;
        $last  = null;
        $args[0]->run($input, static function (mixed $value) use (&$found, &$last): void {
            $found = true;
            $last  = $value;
        });
        if ($found) {
            $emit($last);
        }
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function isEmpty(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $found = EarlyExit::run(static function (object $label) use ($input, $args): void {
            $args[0]->run($input, static function () use ($label): never {
                EarlyExit::stop($label);
            });
        });

        $emit(!$found);
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function any(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $emit(self::quantify($input, $args, true));
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function all(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $emit(!self::quantify($input, $args, false));
    }

    /**
     * Whether some output of `generator | condition` is truthy ($truthy) or falsy (not $truthy), stopping at the
     * first one that is.
     *
     * @param list<Filter> $args
     */
    private static function quantify(mixed $input, array $args, bool $truthy): bool
    {
        return EarlyExit::run(static function (object $label) use ($input, $args, $truthy): void {
            $args[0]->run($input, static function (mixed $item) use ($args, $label, $truthy): void {
                $args[1]->run($item, static function (mixed $condition) use ($label, $truthy): void {
                    if (Values::isTruthy($condition) === $truthy) {
                        EarlyExit::stop($label);
                    }
                });
            });
        });
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function range1(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $upto) use ($emit): void {
            self::rangeLoop(0, $upto, 1.0, $emit);
        });
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function range2(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $from) use ($input, $args, $emit): void {
            $args[1]->run($input, static function (mixed $upto) use ($from, $emit): void {
                self::rangeLoop($from, $upto, 1.0, $emit);
            });
        });
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function range3(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $from) use ($input, $args, $emit): void {
            $args[1]->run($input, static function (mixed $upto) use ($from, $input, $args, $emit): void {
                $args[2]->run($input, static function (mixed $by) use ($from, $upto, $emit): void {
                    if (!Num::isNumber($by)) {
                        throw new JqException('Range bounds must be numeric');
                    }

                    self::rangeLoop($from, $upto, Num::toFloat($by), $emit);
                });
            });
        });
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function rangeLoop(mixed $from, mixed $upto, float $by, Closure $emit): void
    {
        if (!Num::isNumber($from) || !Num::isNumber($upto)) {
            throw new JqException('Range bounds must be numeric');
        }

        if (\is_int($from) && \is_int($upto) && 1.0 === $by) {
            for ($i = $from; $i < $upto; ++$i) {
                $emit($i);
            }

            return;
        }

        $value = Num::toFloat($from);
        $end   = Num::toFloat($upto);
        if ($by > 0) {
            for (; $value < $end; $value += $by) {
                $emit(Num::of($value));
            }
        } elseif ($by < 0) {
            for (; $value > $end; $value += $by) {
                $emit(Num::of($value));
            }
        }
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function recurse0(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        self::descend($input, $emit);
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function descend(mixed $value, Closure $emit): void
    {
        $emit($value);
        if (\is_array($value)) {
            foreach ($value as $child) {
                self::descend($child, $emit);
            }
        } elseif ($value instanceof JsonObject) {
            foreach ($value->toArray() as $child) {
                self::descend($child, $emit);
            }
        }
    }

    /**
     * @param ?list<mixed>                       $path
     * @param list<Filter>                       $args
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function recurse0Paths(RuntimeContext $c, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        self::descendPaths($path, $input, $emit);
    }

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function descendPaths(?array $path, mixed $value, Closure $emit): void
    {
        $emit($path, $value);
        if (\is_array($value)) {
            foreach ($value as $index => $child) {
                self::descendPaths(null === $path ? null : [...$path, $index], $child, $emit);
            }
        } elseif ($value instanceof JsonObject) {
            foreach ($value->toArray() as $key => $child) {
                self::descendPaths(null === $path ? null : [...$path, (string)$key], $child, $emit);
            }
        }
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function recurse1(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        $step = $args[0];
        $walk = static function (mixed $value) use (&$walk, $step, $emit): void {
            $emit($value);
            $step->run($value, $walk);
        };
        $walk($input);
    }

    /**
     * @param ?list<mixed>                       $path
     * @param list<Filter>                       $args
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function recurse1Paths(RuntimeContext $c, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        self::walkSteps($args[0], $path, $input, $emit);
    }

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function walkSteps(Filter $step, ?array $path, mixed $value, Closure $emit): void
    {
        $emit($path, $value);
        $step->paths($path, $value, static function (?array $childPath, mixed $child) use ($step, $emit): void {
            self::walkSteps($step, $childPath, $child, $emit);
        });
    }

    /**
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function recurse2(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        [$step, $condition] = $args;
        $walk               = static function (mixed $value) use (&$walk, $step, $condition, $emit): void {
            $emit($value);
            $step->run($value, static function (mixed $child) use (&$walk, $condition): void {
                $condition->run($child, static function (mixed $keep) use (&$walk, $child): void {
                    if (null !== $keep && false !== $keep) {
                        $walk($child);
                    }
                });
            });
        };
        $walk($input);
    }

    /**
     * @param ?list<mixed>                       $path
     * @param list<Filter>                       $args
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function recurse2Paths(RuntimeContext $c, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        self::walkGuardedSteps($args[0], $args[1], $path, $input, $emit);
    }

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function walkGuardedSteps(Filter $step, Filter $condition, ?array $path, mixed $value, Closure $emit): void
    {
        $emit($path, $value);
        $step->paths($path, $value, static function (?array $childPath, mixed $child) use ($step, $condition, $emit): void {
            $condition->run($child, static function (mixed $keep) use ($step, $condition, $emit, $childPath, $child): void {
                if (null !== $keep && false !== $keep) {
                    self::walkGuardedSteps($step, $condition, $childPath, $child, $emit);
                }
            });
        });
    }

    /**
     * Applies the filter to the same input again and again, emitting everything it produces; it ends only by
     * an error or a break from the caller.
     *
     * @param list<Filter>         $args
     * @param Closure(mixed): void $emit
     */
    private static function repeat(RuntimeContext $c, mixed $input, array $args, Closure $emit): void
    {
        foreach (new InfiniteIterator(new ArrayIterator([true])) as $ignored) {
            $args[0]->run($input, $emit);
        }
    }
}
