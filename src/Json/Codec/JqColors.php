<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json\Codec;

use LTS\PhpXq\Json\ColorScheme;

/**
 * The JQ_COLORS environment variable: up to eight colon separated SGR parameter lists for null, false, true,
 * numbers, strings, arrays, objects and object keys, each rendered as an escape sequence ("0;32" becomes
 * "ESC[0;32m", an empty field "ESC[m"). Fields that are absent keep jq's defaults.
 *
 * @api
 */
final class JqColors
{
    private const int MAX_FIELD_LENGTH = 30;

    private const int FIELD_COUNT = 8;

    private function __construct()
    {
    }

    /**
     * @return ?ColorScheme null when the specification is invalid (jq then warns "Failed to set $JQ_COLORS"
     *                      and keeps the default palette)
     */
    public static function parse(string $spec): ?ColorScheme
    {
        $defaults = ColorScheme::default();
        $fields   = [
            $defaults->null,
            $defaults->false,
            $defaults->true,
            $defaults->number,
            $defaults->string,
            $defaults->array,
            $defaults->object,
            $defaults->objectKey,
        ];

        if ('' === $spec) {
            return $defaults;
        }

        foreach (explode(':', $spec) as $index => $field) {
            if ($index >= self::FIELD_COUNT) {
                break;
            }

            if (\strlen($field) > self::MAX_FIELD_LENGTH || 1 === preg_match('/[^0-9;]/', $field)) {
                return null;
            }

            $fields[$index] = "\e[" . $field . 'm';
        }

        return new ColorScheme(...$fields);
    }
}
