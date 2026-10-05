<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support;

use Closure;
use LTS\PhpXq\Json\JsonObject;

/**
 * Renders a decoded JSON value (scalars, lists, {@see JsonObject}s) as one comparable string. The caller
 * chooses how a scalar and an object entry read; the list and object framing is shared.
 */
final readonly class JsonValueDescriber
{
    /**
     * @param Closure(mixed): string          $scalar describes a value that is neither a list nor an object
     * @param Closure(string, string): string $entry  joins an object key with its described member
     */
    public function __construct(
        private Closure $scalar,
        private Closure $entry,
    ) {
    }

    public function describe(mixed $value): string
    {
        if (\is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                $items[] = $this->describe($item);
            }

            return '[' . implode(',', $items) . ']';
        }

        if (!$value instanceof JsonObject) {
            return ($this->scalar)($value);
        }

        $parts = [];
        foreach ($value->entries() as $key => $member) {
            $parts[] = ($this->entry)($key, $this->describe($member));
        }

        return '{' . implode(',', $parts) . '}';
    }
}
