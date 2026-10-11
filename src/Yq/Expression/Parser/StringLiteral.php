<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Parser;

/**
 * Decodes the backslash escapes of the expression language's double-quoted string literals. Finding where a
 * string ends and splitting out its interpolations is the lexer's job, done in the same pass as the tokens.
 *
 * @internal
 */
final readonly class StringLiteral
{
    private function __construct()
    {
    }

    /**
     * Decodes backslash escapes. Unknown or malformed escapes are kept verbatim.
     */
    public static function decode(string $body): string
    {
        $slash = strpos($body, '\\');
        if (false === $slash) {
            return $body;
        }

        $out    = '';
        $index  = 0;
        $length = \strlen($body);
        while (false !== $slash) {
            $out .= substr($body, $index, $slash - $index);
            if ($slash + 1 >= $length) {
                $out  .= '\\';
                $index = $length;

                break;
            }

            $char  = $body[$slash + 1];
            $index = $slash + 2;
            $out  .= match ($char) {
                'n'     => "\n",
                't'     => "\t",
                'r'     => "\r",
                '"'     => '"',
                '\\'    => '\\',
                '/'     => '/',
                'a'     => "\x07",
                'b'     => "\x08",
                'f'     => "\x0c",
                'v'     => "\x0b",
                '0'     => "\x00",
                default => self::decodeNumeric($body, $char, $index),
            };
            $slash = strpos($body, '\\', $index);
        }

        return $out . substr($body, $index);
    }

    /**
     * Handles `\xHH`, `\uHHHH` (with surrogate pairs) and `\UHHHHHHHH`; advances $index past what it used.
     */
    private static function decodeNumeric(string $body, string $char, int &$index): string
    {
        $digits = match ($char) {
            'x'     => 2,
            'u'     => 4,
            'U'     => 8,
            default => 0,
        };
        if (0 === $digits) {
            return '\\' . $char;
        }

        $hex = substr($body, $index, $digits);
        if (\strlen($hex) !== $digits || !ctype_xdigit($hex)) {
            return '\\' . $char;
        }

        $index    += $digits;
        $codePoint = (int)hexdec($hex);
        if ('x' === $char) {
            return pack('C', $codePoint);
        }

        if ($codePoint >= 0xD800 && $codePoint <= 0xDBFF) {
            if (1 === preg_match('/\G\\\u([dD][c-fC-F][0-9a-fA-F]{2})/', $body, $match, 0, $index)) {
                $index    += 6;
                $codePoint = 0x10000 + (($codePoint - 0xD800) << 10) + ((int)hexdec($match[1]) - 0xDC00);
            } else {
                $codePoint = 0xFFFD;
            }
        } elseif (($codePoint >= 0xDC00 && $codePoint <= 0xDFFF) || $codePoint > 0x10FFFF) {
            $codePoint = 0xFFFD;
        }

        return self::utf8($codePoint);
    }

    private static function utf8(int $codePoint): string
    {
        return match (true) {
            $codePoint < 0x80    => pack('C', $codePoint),
            $codePoint < 0x800   => pack('CC', 0xC0 | ($codePoint >> 6), 0x80 | ($codePoint & 0x3F)),
            $codePoint < 0x10000 => pack('CCC', 0xE0 | ($codePoint >> 12), 0x80 | (($codePoint >> 6) & 0x3F), 0x80 | ($codePoint & 0x3F)),
            default              => pack('CCCC', 0xF0 | ($codePoint >> 18), 0x80 | (($codePoint >> 12) & 0x3F), 0x80 | (($codePoint >> 6) & 0x3F), 0x80 | ($codePoint & 0x3F)),
        };
    }
}
