<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Conformance;

use Closure;
use LTS\PhpXq\Tests\Support\CliRunner;

/**
 * One upstream conformance case: a stable id plus the check that runs it through the CLI.
 */
final readonly class ConformanceCase
{
    /**
     * @param Closure(CliRunner): ?string $evaluator returns null when the case passes, else a failure message
     */
    public function __construct(
        public string $id,
        private Closure $evaluator,
    ) {
    }

    public function evaluate(CliRunner $runner): ?string
    {
        return ($this->evaluator)($runner);
    }
}
