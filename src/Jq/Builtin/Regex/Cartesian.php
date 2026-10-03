<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use Closure;
use LTS\PhpXq\Jq\Runtime\Filter;

/**
 * Evaluates closure parameters the way jq expands `def f($a; $b): ...`: the first parameter is the
 * outermost loop, so `f(1,2; 10,20)` runs the body for (1,10), (1,20), (2,10), (2,20).
 *
 * @internal
 */
final class Cartesian
{
    private function __construct()
    {
    }

    /**
     * @param list<Filter>               $filters
     * @param Closure(list<mixed>): void $body
     */
    public static function each(array $filters, mixed $input, Closure $body): void
    {
        self::step($filters, 0, [], $input, $body);
    }

    /**
     * @param list<Filter>               $filters
     * @param list<mixed>                $values
     * @param Closure(list<mixed>): void $body
     */
    private static function step(array $filters, int $index, array $values, mixed $input, Closure $body): void
    {
        if ($index === \count($filters)) {
            $body($values);

            return;
        }

        $filters[$index]->run($input, static function (mixed $value) use ($filters, $index, $values, $input, $body): void {
            self::step($filters, $index + 1, [...$values, $value], $input, $body);
        });
    }
}
