<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use Closure;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\PathStreamBuiltinInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;

/**
 * A {@see PathStreamBuiltinInterface} that emits its input once, in both modes, after calling its first argument for
 * its side effects (so tests can observe how the closure parameter was bound).
 */
final readonly class CallbackPathStreamBuiltin implements PathStreamBuiltinInterface
{
    public function __construct(
        private string $name,
        private int $arity,
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

    public function run(RuntimeContextInterface $context, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $emit($input);
    }

    public function runPaths(RuntimeContextInterface $context, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $emit($path, $input);
        $emit([...($path ?? []), 'extra'], 'extra-value');
    }
}
