<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use Closure;
use LTS\PhpXq\Jq\Runtime\CompiledProgram;
use LTS\PhpXq\Jq\Runtime\RuntimeContext;

/**
 * A compiled program that runs a closure.
 *
 * @internal
 */
final readonly class JqApplicationFakeProgram implements CompiledProgram
{
    /**
     * @param Closure(RuntimeContext, mixed, Closure(mixed): void): void $behaviour
     */
    public function __construct(
        private Closure $behaviour,
    ) {
    }

    public function run(RuntimeContext $context, mixed $input, Closure $emit): void
    {
        ($this->behaviour)($context, $input, $emit);
    }
}
