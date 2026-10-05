<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use ArrayIterator;
use Closure;
use InfiniteIterator;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\Values;

/**
 * Generators and control: empty, error, select, map, range, limit, first, last, skip, isempty, any, all,
 * recurse and repeat. Those valid in a path expression (`path(first(.a, .b))`) are path-aware.
 *
 * @internal
 */
final readonly class ControlFunctions
{
    private const string RANGE   = 'range';

    private const string RECURSE = 'recurse';

    private function __construct()
    {
    }

    public static function register(BuiltinRegistryInterface $registry): void
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
        $registry->register(new StreamFunction(self::RANGE, 1, self::range1(...)));
        $registry->register(new StreamFunction(self::RANGE, 2, self::range2(...)));
        $registry->register(new StreamFunction(self::RANGE, 3, self::range3(...)));
        $registry->register(new PathStreamFunction(self::RECURSE, 0, self::recurse0(...), self::recurse0Paths(...)));
        $registry->register(new PathStreamFunction(self::RECURSE, 1, self::recurse1(...), self::recurse1Paths(...)));
        $registry->register(new PathStreamFunction(self::RECURSE, 2, self::recurse2(...), self::recurse2Paths(...)));
        $registry->register(new StreamFunction('repeat', 1, self::repeat(...)));
    }

    private static function nothing(): void
    {
    }

    private static function nothingPaths(): void
    {
    }

    private static function error0(RuntimeContextInterface $c, mixed $input): never
    {
        throw new JqException($input);
    }

    private static function error0Paths(RuntimeContextInterface $c, mixed $path, mixed $input): never
    {
        throw new JqException($input);
    }

    private static function error1(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $args[0]->run($input, static function (mixed $message): never {
            throw new JqException($message);
        });
    }

    /**
     * @param ?list<mixed> $path
     */
    private static function error1Paths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        self::error1($c, $input, static function (): void {
        }, ...$args);
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function select(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $args[0]->run($input, static function (mixed $condition) use ($input, $emit): void {
            if (null !== $condition && false !== $condition) {
                $emit($input);
            }
        });
    }

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function selectPaths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $args[0]->run($input, static function (mixed $condition) use ($path, $input, $emit): void {
            if (null !== $condition && false !== $condition) {
                $emit($path, $input);
            }
        });
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function map(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(mixed): void $emit
     */
    private static function first(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function firstPaths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        EarlyExit::run(static function (object $label) use ($path, $input, $args, $emit): void {
            $args[0]->paths($path, $input, static function (?array $where, mixed $value) use ($emit, $label): never {
                $emit($where, $value);
                EarlyExit::stop($label);
            });
        });
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function limit(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function limitPaths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(mixed): void $emit
     */
    private static function skip(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function skipPaths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(mixed): void $emit
     */
    private static function last(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(mixed): void $emit
     */
    private static function isEmpty(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $found = EarlyExit::run(static function (object $label) use ($input, $args): void {
            $args[0]->run($input, static function () use ($label): never {
                EarlyExit::stop($label);
            });
        });

        $emit(!$found);
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function any(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $emit(self::quantify($input, true, ...$args));
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function all(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $emit(!self::quantify($input, false, ...$args));
    }

    /**
     * Whether some output of `generator | condition` is truthy ($truthy) or falsy (not $truthy), stopping at the
     * first one that is.
     */
    private static function quantify(mixed $input, bool $truthy, FilterInterface ...$args): bool
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
     * @param Closure(mixed): void $emit
     */
    private static function range1(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $args[0]->run($input, static function (mixed $upto) use ($emit): void {
            self::rangeLoop(0, $upto, 1.0, $emit);
        });
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function range2(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $args[0]->run($input, static function (mixed $from) use ($input, $args, $emit): void {
            $args[1]->run($input, static function (mixed $upto) use ($from, $emit): void {
                self::rangeLoop($from, $upto, 1.0, $emit);
            });
        });
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function range3(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(mixed): void $emit
     */
    private static function recurse0(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function recurse0Paths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(mixed): void $emit
     */
    private static function recurse1(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function recurse1Paths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        self::walkSteps($args[0], $path, $input, $emit);
    }

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function walkSteps(FilterInterface $step, ?array $path, mixed $value, Closure $emit): void
    {
        $emit($path, $value);
        $step->paths($path, $value, static function (?array $childPath, mixed $child) use ($step, $emit): void {
            self::walkSteps($step, $childPath, $child, $emit);
        });
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function recurse2(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
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
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function recurse2Paths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        self::walkGuardedSteps($args[0], $args[1], $path, $input, $emit);
    }

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function walkGuardedSteps(FilterInterface $step, FilterInterface $condition, ?array $path, mixed $value, Closure $emit): void
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
     * @param Closure(mixed): void $emit
     */
    private static function repeat(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        foreach (new InfiniteIterator(new ArrayIterator([true])) as $ignored) {
            $args[0]->run($input, $emit);
        }
    }
}
