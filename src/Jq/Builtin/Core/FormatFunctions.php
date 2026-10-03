<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\Codec\Utf8;
use LTS\PhpXq\Json\JsonObject;

/**
 * The `@format` strings behind `format("name")` and `@name`: text, json, csv, tsv, html, uri, urid, sh,
 * base64, base64d, base32 and base32d. The evaluator compiles `@name` to a call of `format/1`.
 *
 * @internal
 */
final class FormatFunctions
{
    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const string BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private function __construct()
    {
    }

    public static function register(BuiltinRegistryInterface $registry): void
    {
        $registry->register(new ValueFunction('format', 1, static function (RuntimeContextInterface $c, mixed $v, array $a): mixed {
            if (!\is_string($a[0])) {
                throw Problems::type($a[0], 'is not a valid format');
            }

            return self::apply($a[0], $v);
        }));
    }

    /**
     * Apply the format $name (without the `@`) to a value.
     */
    public static function apply(string $name, mixed $value): string
    {
        return match ($name) {
            'text'    => \is_string($value) ? $value : Problems::json($value),
            'json'    => Problems::json($value),
            'csv'     => self::csv($value),
            'tsv'     => self::tsv($value),
            'html'    => self::html(self::text($value)),
            'uri'     => self::uri(self::text($value)),
            'urid'    => self::uriDecode(self::text($value)),
            'sh'      => self::shell($value),
            'base64'  => self::base64(self::text($value)),
            'base64d' => self::base64Decode(self::text($value)),
            'base32'  => self::base32(self::text($value)),
            'base32d' => self::base32Decode(self::text($value)),
            default   => throw new JqException($name . ' is not a valid format'),
        };
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : Problems::json($value);
    }

    private static function csv(mixed $value): string
    {
        if (!\is_array($value)) {
            throw Problems::type($value, 'cannot be csv-formatted, only an array can be');
        }

        $cells = [];
        foreach ($value as $cell) {
            if (\is_string($cell)) {
                $cells[] = '"' . str_replace('"', '""', $cell) . '"';
            } elseif (null === $cell) {
                $cells[] = '';
            } elseif (\is_bool($cell) || Num::isNumber($cell)) {
                $cells[] = Problems::json($cell);
            } else {
                throw Problems::type($cell, 'is not valid in a csv row');
            }
        }

        return implode(',', $cells);
    }

    private static function tsv(mixed $value): string
    {
        if (!\is_array($value)) {
            throw Problems::type($value, 'cannot be tsv-formatted, only an array can be');
        }

        $cells = [];
        foreach ($value as $cell) {
            if (\is_string($cell)) {
                $cells[] = strtr($cell, ["\t" => '\t', "\n" => '\n', "\r" => '\r', '\\' => '\\\\']);
            } elseif (null === $cell) {
                $cells[] = '';
            } elseif (\is_bool($cell) || Num::isNumber($cell)) {
                $cells[] = Problems::json($cell);
            } else {
                throw Problems::type($cell, 'is not valid in a tsv row');
            }
        }

        return implode("\t", $cells);
    }

    private static function html(string $text): string
    {
        return strtr($text, ['<' => '&lt;', '>' => '&gt;', '&' => '&amp;', "'" => '&apos;', '"' => '&quot;']);
    }

    private static function uri(string $text): string
    {
        $out    = '';
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $byte = $text[$i];
            if (ctype_alnum($byte) || '-' === $byte || '_' === $byte || '.' === $byte || '~' === $byte) {
                $out .= $byte;

                continue;
            }

            $out .= '%' . strtoupper(bin2hex($byte));
        }

        return $out;
    }

    private static function uriDecode(string $text): string
    {
        if (!str_contains($text, '%')) {
            return $text;
        }

        if (1 === preg_match('/%(?![0-9A-Fa-f]{2})/', $text)) {
            throw Problems::type($text, 'is not a valid uri encoding');
        }

        $out = rawurldecode($text);
        if (1 !== preg_match('//u', $out)) {
            throw Problems::type($text, 'is not a valid uri encoding');
        }

        return $out;
    }

    private static function shell(mixed $value): string
    {
        $items = \is_array($value) ? $value : [$value];
        $out   = [];
        foreach ($items as $item) {
            if (\is_string($item)) {
                $out[] = "'" . str_replace("'", "'\\''", $item) . "'";
            } elseif ($item instanceof JsonObject || \is_array($item)) {
                throw Problems::type($item, 'can not be escaped for shell');
            } else {
                $out[] = Problems::json($item);
            }
        }

        return implode(' ', $out);
    }

    private static function base64(string $text): string
    {
        return base64_encode($text);
    }

    private static function base64Decode(string $text): string
    {
        $length = \strlen($text);
        $out    = '';
        $buffer = 0;
        $count  = 0;
        for ($i = 0; $i < $length && '=' !== $text[$i]; ++$i) {
            $index = strpos(self::BASE64_ALPHABET, $text[$i]);
            if (false === $index) {
                throw Problems::type($text, 'is not valid base64 data');
            }

            $buffer = ($buffer << 6) | $index;
            ++$count;
            if (4 === $count) {
                $out .= \chr(($buffer >> 16) & 0xFF) . \chr(($buffer >> 8) & 0xFF) . \chr($buffer & 0xFF);
                $buffer = 0;
                $count  = 0;
            }
        }

        if (3 === $count) {
            $out .= \chr(($buffer >> 10) & 0xFF) . \chr(($buffer >> 2) & 0xFF);
        } elseif (2 === $count) {
            $out .= \chr(($buffer >> 4) & 0xFF);
        } elseif (1 === $count) {
            throw Problems::type($text, 'trailing base64 byte found');
        }

        return self::utf8($out);
    }

    private static function base32(string $text): string
    {
        $out    = '';
        $buffer = 0;
        $bits   = 0;
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $buffer = ($buffer << 8) | \ord($text[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::BASE32_ALPHABET[($buffer >> $bits) & 31];
            }

            $buffer &= (1 << $bits) - 1;
        }

        if ($bits > 0) {
            $out .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $out . str_repeat('=', (8 - \strlen($out) % 8) % 8);
    }

    private static function base32Decode(string $text): string
    {
        $out    = '';
        $buffer = 0;
        $bits   = 0;
        $length = \strlen($text);
        for ($i = 0; $i < $length && '=' !== $text[$i]; ++$i) {
            $index = strpos(self::BASE32_ALPHABET, $text[$i]);
            if (false === $index) {
                throw Problems::type($text, 'is not valid base32 data');
            }

            $buffer = ($buffer << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= \chr(($buffer >> $bits) & 0xFF);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return self::utf8($out);
    }

    /**
     * Decoded bytes become a string value, so invalid UTF-8 is replaced by U+FFFD as jq does.
     */
    private static function utf8(string $bytes): string
    {
        return Utf8::sanitize($bytes);
    }
}
