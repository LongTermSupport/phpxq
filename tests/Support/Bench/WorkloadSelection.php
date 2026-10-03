<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * Which workloads a run includes. `sizes` limits only the record corpora (small, medium, large); the
 * tiny, wide and deep corpora are always eligible.
 */
final readonly class WorkloadSelection
{
    /**
     * @param list<string> $sizes
     * @param list<string> $tools
     */
    public function __construct(
        public array $sizes = ['small', 'medium'],
        public array $tools = ['jq', 'yq'],
        public ?string $nameContains = null,
    ) {
    }

    public function accepts(Workload $workload): bool
    {
        if (!\in_array($workload->tool, $this->tools, true)) {
            return false;
        }

        if (\in_array($workload->corpus, ['small', 'medium', 'large'], true) && !\in_array($workload->corpus, $this->sizes, true)) {
            return false;
        }

        return null === $this->nameContains || str_contains($workload->id, $this->nameContains);
    }
}
