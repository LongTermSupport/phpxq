<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use Closure;
use LTS\PhpXq\Jq\Runtime\FilterInterface;

/**
 * Evaluates closure parameters the way jq expands `def f($a; $b): ...`: the first parameter is the
 * outermost loop, so `f(1,2; 10,20)` runs the body for (1,10), (1,20), (2,10), (2,20).
 *
 * @internal
 */
final readonly class Cartesian
{
    private function __construct()
    {
    }

    /**
     * @param Closure(list<mixed>): void $body
     */
    public static function each(mixed $input, Closure $body, FilterInterface ...$filters): void
    {
        self::step(array_values($filters), 0, $input, $body);
    }

    /**
     * @param list<FilterInterface>      $filters
     * @param Closure(list<mixed>): void $body
     */
    private static function step(array $filters, int $index, mixed $input, Closure $body, mixed ...$values): void
    {
        if ($index === \count($filters)) {
            $body(array_values($values));

            return;
        }

        $filters[$index]->run($input, static function (mixed $value) use ($filters, $index, $values, $input, $body): void {
            self::step($filters, $index + 1, $input, $body, ...[...$values, $value]);
        });
    }
}
