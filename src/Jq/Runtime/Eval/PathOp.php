<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `path(expression)`: the paths of every output of a path expression.
 *
 * @internal
 */
final class PathOp extends AbstractOp
{
    public function __construct(private readonly Op $expression)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->expression->paths($env, [], $input, static function (?array $path, mixed $value) use ($emit): void {
            if (null === $path) {
                throw PathErrors::result($value);
            }

            $emit($path);
        });
    }
}
