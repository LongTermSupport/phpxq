<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use Countable;
use Generator;

/**
 * A JSON object: an immutable, insertion-ordered map from string keys to JSON values.
 *
 * PHP arrays silently turn the key "1" into the int 1. That conversion is bijective for canonical
 * decimal strings, so members are stored in a plain PHP array and every key is cast back to string on
 * the way out. Callers therefore only ever see string keys.
 *
 * @api
 */
final readonly class JsonObject implements Countable
{
    /**
     * @param array<array-key, mixed> $members storage form; keys may be PHP ints for numeric-looking strings
     */
    public function __construct(private array $members = [])
    {
    }

    /**
     * @param iterable<array-key, mixed> $pairs int keys (PHP's form of "1") are read back as strings
     */
    public static function fromPairs(iterable $pairs): self
    {
        $members = [];
        foreach ($pairs as $key => $value) {
            $members[$key] = $value;
        }

        return new self($members);
    }

    /**
     * The members in insertion order as PHP's storage array. Numeric looking names appear as int keys, so a
     * caller that needs string keys casts them. Read only use: the array is a copy-on-write snapshot.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->members;
    }

    public function count(): int
    {
        return \count($this->members);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->members);
    }

    /**
     * The value for $key, or null when the key is absent (jq semantics: a missing key reads as null).
     */
    public function get(string $key): mixed
    {
        return $this->members[$key] ?? null;
    }

    public function with(string $key, mixed $value): self
    {
        $members       = $this->members;
        $members[$key] = $value;

        return new self($members);
    }

    public function without(string $key): self
    {
        $members = $this->members;
        unset($members[$key]);

        return new self($members);
    }

    /**
     * Keys in insertion order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->members));
    }

    /**
     * Keys sorted by Unicode codepoint (byte order of the UTF-8 encoding), as jq's `keys` does.
     *
     * @return list<string>
     */
    public function sortedKeys(): array
    {
        $keys = $this->keys();
        sort($keys, \SORT_STRING);

        return $keys;
    }

    /**
     * Values in insertion order.
     *
     * @return list<mixed>
     */
    public function values(): array
    {
        return array_values($this->members);
    }

    /**
     * @return Generator<string, mixed>
     */
    public function entries(): Generator
    {
        foreach ($this->members as $key => $value) {
            yield (string)$key => $value;
        }
    }
}
