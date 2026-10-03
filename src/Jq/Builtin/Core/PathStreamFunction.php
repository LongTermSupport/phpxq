<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\Filter;
use LTS\PhpXq\Jq\Runtime\PathStreamBuiltin;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;

/**
 * A {@see PathStreamBuiltin} backed by two closures, one for value mode and one for path mode.
 *
 * @internal
 */
final readonly class PathStreamFunction implements PathStreamBuiltin
{
    /**
     * @param Closure(RuntimeContext, mixed, list<Filter>, Closure(mixed): void): void                             $values
     * @param Closure(RuntimeContext, ?list<mixed>, mixed, list<Filter>, Closure(?list<mixed>, mixed): void): void $paths
     */
    public function __construct(
        private string $name,
        private int $arity,
        private Closure $values,
        private Closure $paths,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function arity(): int
    {
        return $this->arity;
    }

    public function run(RuntimeContext $context, mixed $input, array $args, Closure $emit): void
    {
        ($this->values)($context, $input, $args, $emit);
    }

    public function runPaths(RuntimeContext $context, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        ($this->paths)($context, $path, $input, $args, $emit);
    }
}
