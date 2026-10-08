<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use ArrayIterator;
use Closure;
use InfiniteIterator;
use LogicException;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\Values;
use SplQueue;
use Throwable;

/**
 * Generators and control: empty, error, select, map, range, limit, first, last, skip, isempty, any, all,
 * recurse, repeat, until and while. Those valid in a path expression (`path(first(.a, .b))`) are path-aware.
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
        $registry->register(new PathStreamFunction('until', 2, self::until(...), self::untilPaths(...)));
        $registry->register(new PathStreamFunction('while', 2, self::loopWhile(...), self::loopWhilePaths(...)));
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

    /**
     * `def until(cond; update): def _until: if cond then . else (update | _until) end; _until;`
     *
     * @param Closure(mixed): void $emit
     */
    private static function until(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        [$condition, $update] = $args;
        self::loop($input, static function (mixed $value, Closure $continue, Closure $flush) use ($condition, $update, $emit): void {
            $condition->run($value, static function (mixed $done) use ($value, $update, $emit, $continue, $flush): void {
                $flush();
                if (Values::isTruthy($done)) {
                    $emit($value);

                    return;
                }

                $update->run($value, $continue);
            });
        });
    }

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function untilPaths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        [$condition, $update] = $args;
        self::loop([$path, $input], static function (mixed $state, Closure $continue, Closure $flush) use ($condition, $update, $emit): void {
            [$at, $value] = self::pathState($state);
            $condition->run($value, static function (mixed $done) use ($at, $value, $update, $emit, $continue, $flush): void {
                $flush();
                if (Values::isTruthy($done)) {
                    $emit($at, $value);

                    return;
                }

                $update->paths($at, $value, static function (?array $next, mixed $child) use ($continue): void {
                    $continue([$next, $child]);
                });
            });
        });
    }

    /**
     * `def while(cond; update): def _while: if cond then ., (update | _while) else empty end; _while;`
     *
     * @param Closure(mixed): void $emit
     */
    private static function loopWhile(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        [$condition, $update] = $args;
        self::loop($input, static function (mixed $value, Closure $continue, Closure $flush) use ($condition, $update, $emit): void {
            $condition->run($value, static function (mixed $going) use ($value, $update, $emit, $continue, $flush): void {
                $flush();
                if (!Values::isTruthy($going)) {
                    return;
                }

                $emit($value);
                $update->run($value, $continue);
            });
        });
    }

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function loopWhilePaths(RuntimeContextInterface $c, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        [$condition, $update] = $args;
        self::loop([$path, $input], static function (mixed $state, Closure $continue, Closure $flush) use ($condition, $update, $emit): void {
            [$at, $value] = self::pathState($state);
            $condition->run($value, static function (mixed $going) use ($at, $value, $update, $emit, $continue, $flush): void {
                $flush();
                if (!Values::isTruthy($going)) {
                    return;
                }

                $emit($at, $value);
                $update->paths($at, $value, static function (?array $next, mixed $child) use ($continue): void {
                    $continue([$next, $child]);
                });
            });
        });
    }

    /**
     * @return array{?list<mixed>, mixed}
     */
    private static function pathState(mixed $state): array
    {
        if (!\is_array($state) || !\array_key_exists(0, $state) || !\array_key_exists(1, $state)) {
            throw new LogicException('A path loop state is a path and a value');
        }

        $path = $state[0];
        if (null !== $path && (!\is_array($path) || !array_is_list($path))) {
            throw new LogicException('A path loop state starts with a path');
        }

        return [$path, $state[1]];
    }

    /**
     * Runs a jq loop whose recursive call sits in tail position. Each step either emits or hands the states to
     * continue from to `$continue`; the last state a step hands over becomes the next iteration, and only the
     * earlier ones (a branching condition or update) recurse, so a loop with one state per step runs in
     * constant stack, as jq's tail-call optimisation runs it. A step calls `$flush` before it emits, and a step
     * that throws has its earlier states run first, so outputs and errors come in jq's order.
     *
     * @param Closure(mixed, Closure(mixed): void, Closure(): void): void $step
     *
     * @throws JqException past {@see EvaluationStack::MAX_CALL_DEPTH} nested branches
     */
    private static function loop(mixed $state, Closure $step, int $branches = 0): void
    {
        if ($branches > EvaluationStack::MAX_CALL_DEPTH) {
            throw JqException::fromMessage(EvaluationStack::DEPTH_EXCEEDED);
        }

        while (true) {
            /** @var SplQueue<mixed> $pending at most the one state a step handed over last */
            $pending = new SplQueue();
            $flush   = static function () use ($pending, $step, $branches): void {
                if (!$pending->isEmpty()) {
                    self::loop($pending->dequeue(), $step, $branches + 1);
                }
            };
            $continue = static function (mixed $next) use ($pending, $flush): void {
                $flush();
                $pending->enqueue($next);
            };

            try {
                $step($state, $continue, $flush);
            } catch (Throwable $throwable) {
                $flush();

                throw $throwable;
            }

            if ($pending->isEmpty()) {
                return;
            }

            $state = $pending->dequeue();
        }
    }
}
