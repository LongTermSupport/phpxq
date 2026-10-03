<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Schema;

/**
 * YAML 1.2 core schema tag resolution for plain scalars, as the reference yq (go-yaml v3) applies it.
 */
final class CoreSchema
{
    public const string TAG_NULL      = '!!null';

    public const string TAG_BOOL      = '!!bool';

    public const string TAG_INT       = '!!int';

    public const string TAG_FLOAT     = '!!float';

    public const string TAG_STR       = '!!str';

    public const string TAG_SEQ       = '!!seq';

    public const string TAG_MAP       = '!!map';

    public const string TAG_TIMESTAMP = '!!timestamp';

    public const string TAG_BINARY    = '!!binary';

    private const string INT_PATTERN   = '/^(?:[-+]?[0-9]+|0o[0-7]+|0x[0-9a-fA-F]+)$/D';

    private const string FLOAT_PATTERN = '/^(?:[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?|[-+]?\.(?:inf|Inf|INF)|\.(?:nan|NaN|NAN))$/D';

    private function __construct()
    {
    }

    /**
     * The implicit tag of a plain (unquoted) scalar.
     */
    public static function resolve(string $plain): string
    {
        return match (true) {
            \in_array($plain, ['', '~', 'null', 'Null', 'NULL'], true)                   => self::TAG_NULL,
            \in_array($plain, ['true', 'True', 'TRUE', 'false', 'False', 'FALSE'], true) => self::TAG_BOOL,
            1 === preg_match(self::INT_PATTERN, $plain)                                  => self::TAG_INT,
            1 === preg_match(self::FLOAT_PATTERN, $plain)                                => self::TAG_FLOAT,
            default                                                                      => self::TAG_STR,
        };
    }
}
