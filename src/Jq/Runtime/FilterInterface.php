<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use Closure;

/**
 * A compiled, environment-bound jq expression: the unit the evaluator is built from and the form in
 * which closure parameters reach native builtins (`select(f)` gets f as a Filter).
 *
 * The evaluation scheme is push-based continuation passing: a filter calls $emit once per output, in
 * order, and returns when exhausted. Stopping early is done by throwing {@see BreakException}, never by
 * returning a value. Errors are {@see JqException}.
 *
 * Path mode follows jq's path tracking: a path is a list of keys (string, int, or a slice object) from
 * the start of the path expression; null means "this value was computed, not reached by a path", and
 * any attempt to index or iterate such a value, or to end the path expression on it, raises
 * "Invalid path expression with result ...". Filters that cannot produce paths still implement
 * {@see self::paths()} by emitting null paths so that the error is raised lazily, as jq does.
 *
 * @api
 */
interface FilterInterface
{
    /**
     * @param Closure(mixed): void $emit
     *
     * @throws JqException
     */
    public function run(mixed $input, Closure $emit): void;

    /**
     * @param ?list<mixed>                       $path the path of $input, null when $input is not path-derived
     * @param Closure(?list<mixed>, mixed): void $emit called with each result's path and value
     *
     * @throws JqException
     */
    public function paths(?array $path, mixed $input, Closure $emit): void;
}
