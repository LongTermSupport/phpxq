<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support;

/**
 * Collects the outputs a builtin emits, for tests that cannot use {@see Harness::stream()} because they
 * stop the builtin themselves.
 *
 * @internal
 */
final class Collector
{
    /** @var list<mixed> */
    public array $items = [];

    public function collect(mixed $value): void
    {
        $this->items[] = $value;
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
