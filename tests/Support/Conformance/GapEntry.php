<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Conformance;

/**
 * One known-gap line: an fnmatch glob over case ids and the justification for the gap.
 */
final readonly class GapEntry
{
    public function __construct(
        public string $glob,
        public string $reason,
    ) {
    }

    public function matches(string $id): bool
    {
        return fnmatch($this->glob, $id);
    }
}
