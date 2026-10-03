<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use LTS\PhpXq\Json\ColorScheme;

/**
 * The `JQ_COLORS` environment variable: up to eight colon-separated SGR parameter lists, in the order
 * null, false, true, numbers, strings, arrays, objects, object keys. A field holds digits and
 * semicolons only; an empty field means the plain `ESC[m` sequence; fields that are missing keep their
 * default; one bad field rejects the whole variable.
 *
 * @api
 */
final class ColorSchemeParser
{
    private const int FIELDS = 8;

    private function __construct()
    {
    }

    /**
     * The default palette. The object key colour is `1;34`, as the jq 1.8.2 test-suite expects.
     */
    public static function defaults(): ColorScheme
    {
        return new ColorScheme(
            "\e[0;90m",
            "\e[0;39m",
            "\e[0;39m",
            "\e[0;39m",
            "\e[0;32m",
            "\e[1;39m",
            "\e[1;39m",
            "\e[1;34m",
        );
    }

    /**
     * @return ?ColorScheme null when $spec is not a valid JQ_COLORS value
     */
    public static function parse(string $spec): ?ColorScheme
    {
        $scheme = self::defaults();
        $colors = [
            $scheme->null,
            $scheme->false,
            $scheme->true,
            $scheme->number,
            $scheme->string,
            $scheme->array,
            $scheme->object,
            $scheme->objectKey,
        ];

        $length = \strlen($spec);
        $offset = 0;
        for ($index = 0; $index < self::FIELDS && $offset < $length; ++$index) {
            $end = strpos($spec, ':', $offset);
            if (false === $end) {
                $end = $length;
            }

            $field = substr($spec, $offset, $end - $offset);
            if ('' !== $field && \strlen($field) !== strspn($field, '0123456789;')) {
                return null;
            }

            $colors[$index] = "\e[" . $field . 'm';
            $offset         = $end + 1;
        }

        return new ColorScheme(...$colors);
    }
}
