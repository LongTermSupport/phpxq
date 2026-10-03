<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\PathStreamBuiltinInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;

/**
 * A {@see PathStreamBuiltinInterface} backed by two closures, one for value mode and one for path mode.
 *
 * @internal
 */
final readonly class PathStreamFunction implements PathStreamBuiltinInterface
{
    /**
     * @param Closure(RuntimeContextInterface, mixed, list<FilterInterface>, Closure(mixed): void): void                             $values
     * @param Closure(RuntimeContextInterface, ?list<mixed>, mixed, list<FilterInterface>, Closure(?list<mixed>, mixed): void): void $paths
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

    public function run(RuntimeContextInterface $context, mixed $input, array $args, Closure $emit): void
    {
        ($this->values)($context, $input, $args, $emit);
    }

    public function runPaths(RuntimeContextInterface $context, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        ($this->paths)($context, $path, $input, $args, $emit);
    }
}
