<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * One measured scenario: a tool run with a filter over a named corpus. `batch` is how many invocations
 * make up one timing sample (many-small-invocations workloads use more than one).
 */
final readonly class Workload
{
    public function __construct(
        public string $id,
        public string $tool,
        public string $corpus,
        public string $filter,
        public int $batch = 1,
    ) {
    }
}
