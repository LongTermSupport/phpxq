<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use LTS\PhpXq\Json\JsonObject;

/**
 * The kinds of value `add` sums without the generic path: strings, lists, objects and plain numbers.
 *
 * @internal
 */
enum SimpleKindEnum
{
    public static function of(mixed $value): self
    {
        return match (true) {
            \is_string($value)                 => self::Text,
            \is_array($value)                  => self::Sequence,
            $value instanceof JsonObject       => self::Mapping,
            \is_int($value), \is_float($value) => self::Number,
            default                            => self::Other,
        };
    }

    case Text;

    case Sequence;

    case Mapping;

    case Number;

    case Other;
}
