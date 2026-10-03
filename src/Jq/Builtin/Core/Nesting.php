<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use LTS\PhpXq\Json\JsonObject;

/**
 * Nesting depth of a value, with the limit jq applies to structural comparison: more than 10001 nested
 * containers (a wrapper count above 10000) is "too deep".
 *
 * @internal
 */
final class Nesting
{
    private const int LIMIT = 10001;

    private function __construct()
    {
    }

    /**
     * Whether the value nests containers deeper than jq compares (scalars and empty containers are shallow).
     */
    public static function tooDeep(mixed $value): bool
    {
        if (!\is_array($value) && !$value instanceof JsonObject) {
            return false;
        }

        return self::exceeds($value, 1);
    }

    private static function exceeds(mixed $value, int $depth): bool
    {
        if ($depth > self::LIMIT) {
            return true;
        }

        if (\is_array($value)) {
            foreach ($value as $child) {
                if ((\is_array($child) || $child instanceof JsonObject) && self::exceeds($child, $depth + 1)) {
                    return true;
                }
            }
        } elseif ($value instanceof JsonObject) {
            foreach ($value->toArray() as $child) {
                if ((\is_array($child) || $child instanceof JsonObject) && self::exceeds($child, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
