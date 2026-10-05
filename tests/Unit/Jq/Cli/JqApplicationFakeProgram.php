<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use Closure;
use LTS\PhpXq\Jq\Runtime\CompiledProgramInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;

/**
 * A compiled program that runs a closure.
 *
 * @internal
 */
final readonly class JqApplicationFakeProgram implements CompiledProgramInterface
{
    /**
     * @param Closure(RuntimeContextInterface, mixed, Closure(mixed): void): void $behaviour
     */
    public function __construct(
        private Closure $behaviour,
    ) {
    }

    public function run(RuntimeContextInterface $context, mixed $input, Closure $emit): void
    {
        ($this->behaviour)($context, $input, $emit);
    }
}
