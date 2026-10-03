<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use Closure;
use LTS\PhpXq\Jq\Runtime\PathStreamBuiltin;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;

/**
 * A {@see PathStreamBuiltin} that emits its input once, in both modes, after calling its first argument for
 * its side effects (so tests can observe how the closure parameter was bound).
 */
final readonly class CallbackPathStreamBuiltin implements PathStreamBuiltin
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

    public function run(RuntimeContext $context, mixed $input, array $args, Closure $emit): void
    {
        $emit($input);
    }

    public function runPaths(RuntimeContext $context, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        $emit($path, $input);
        $emit([...($path ?? []), 'extra'], 'extra-value');
    }
}
