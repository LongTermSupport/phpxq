<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Parser;

use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;

/**
 * Byte-level helpers for the string literals of the expression language: finding the closing quote of a
 * double-quoted string (skipping `\(...)` interpolations, which may themselves hold quotes), decoding
 * backslash escapes and splitting a string body into literal text and interpolated sources.
 *
 * @internal
 */
final class StringLiteral
{
    private const string UNTERMINATED = 'Bad expression, unterminated string';

    private function __construct()
    {
    }

    /**
     * The byte index of the closing `"` of a double-quoted string whose body starts at $start.
     *
     * @throws ExpressionSyntaxException
     */
    public static function scanDouble(string $source, int $start): int
    {
        $length = \strlen($source);
        $index  = $start;
        while ($index < $length) {
            $index += strcspn($source, '"\\', $index);
            if ($index >= $length) {
                break;
            }

            if ('"' === $source[$index]) {
                return $index;
            }

            $index = ($index + 1 < $length && '(' === $source[$index + 1])
                ? self::skipInterpolation($source, $index + 2)
                : $index + 2;
        }

        throw new ExpressionSyntaxException(self::UNTERMINATED, $start - 1);
    }

    /**
     * The byte index just after the `)` closing an interpolation whose body starts at $start (the byte
     * after the opening `\(`). Nested parentheses and quoted strings inside are skipped.
     *
     * @throws ExpressionSyntaxException
     */
    public static function skipInterpolation(string $source, int $start): int
    {
        $length = \strlen($source);
        $depth  = 1;
        $index  = $start;
        while ($index < $length) {
            $index += strcspn($source, '()"\'', $index);
            if ($index >= $length) {
                break;
            }

            $char = $source[$index];
            if ('(' === $char) {
                ++$depth;
                ++$index;

                continue;
            }

            if (')' === $char) {
                --$depth;
                ++$index;
                if (0 === $depth) {
                    return $index;
                }

                continue;
            }

            if ('"' === $char) {
                $index = self::scanDouble($source, $index + 1) + 1;

                continue;
            }

            $close = strpos($source, "'", $index + 1);
            if (false === $close) {
                break;
            }

            $index = $close + 1;
        }

        throw new ExpressionSyntaxException('Bad expression, could not find matching `)`', $start);
    }

    /**
     * Splits a double-quoted string body into decoded literal text and interpolation sources. A literal is a
     * string; an interpolation is `[source, offset of source in $body]`. Empty literals are dropped.
     *
     * @return list<string|array{string, int}>
     *
     * @throws ExpressionSyntaxException
     */
    public static function split(string $body): array
    {
        $parts   = [];
        $pending = '';
        $length  = \strlen($body);
        $index   = 0;
        while ($index < $length) {
            $slash = strpos($body, '\\', $index);
            if (false === $slash) {
                $pending .= substr($body, $index);

                break;
            }

            $pending .= substr($body, $index, $slash - $index);
            if ($slash + 1 < $length && '(' === $body[$slash + 1]) {
                if ('' !== $pending) {
                    $parts[] = self::decode($pending);
                    $pending = '';
                }

                $end     = self::skipInterpolation($body, $slash + 2);
                $parts[] = [substr($body, $slash + 2, $end - 1 - ($slash + 2)), $slash + 2];
                $index   = $end;

                continue;
            }

            $pending .= substr($body, $slash, 2);
            $index    = $slash + 2;
        }

        if ('' !== $pending) {
            $parts[] = self::decode($pending);
        }

        return $parts;
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
