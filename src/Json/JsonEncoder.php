<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use InvalidArgumentException;
use LTS\PhpXq\Json\Codec\NumberFormatter;
use LTS\PhpXq\Json\Codec\Utf8;

/**
 * JSON encoder reproducing jq's jv_dump_term: key order, spacing, escaping and colour sequences are
 * byte-for-byte the ones jq 1.8 prints. Numbers follow {@see NumberFormatter} and
 * {@see PreciseNumber}; strings are escaped as jq does (U+007F and control characters as \u00xx, invalid
 * UTF-8 replaced by U+FFFD, optional \uXXXX for every non-ASCII codepoint).
 *
 * jq prints a value nested more than MAX_PRINT_DEPTH containers deep as the SKIPPED marker. UNSAFE is the bytes
 * JSON output escapes (controls, quote, backslash and DEL); PLAIN_ASCII is printable ASCII without quote and
 * backslash, printed as is when every non-ASCII codepoint is escaped.
 *
 * @api
 */
final class JsonEncoder implements JsonEncoderInterface
{
    private const string RESET = "\e[0m";

    private const int MAX_PRINT_DEPTH = 10000;

    private const string SKIPPED = '<skipped: too deep>';

    private const string UNSAFE = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\"\\\x7f";

    private const string PLAIN_ASCII = '/^[\x20\x21\x23-\x5b\x5d-\x7e]*+$/D';

    private const array ESCAPES = [
        "\x00" => '\u0000',
        "\x01" => '\u0001',
        "\x02" => '\u0002',
        "\x03" => '\u0003',
        "\x04" => '\u0004',
        "\x05" => '\u0005',
        "\x06" => '\u0006',
        "\x07" => '\u0007',
        "\x08" => '\b',
        "\x09" => '\t',
        "\x0a" => '\n',
        "\x0b" => '\u000b',
        "\x0c" => '\f',
        "\x0d" => '\r',
        "\x0e" => '\u000e',
        "\x0f" => '\u000f',
        "\x10" => '\u0010',
        "\x11" => '\u0011',
        "\x12" => '\u0012',
        "\x13" => '\u0013',
        "\x14" => '\u0014',
        "\x15" => '\u0015',
        "\x16" => '\u0016',
        "\x17" => '\u0017',
        "\x18" => '\u0018',
        "\x19" => '\u0019',
        "\x1a" => '\u001a',
        "\x1b" => '\u001b',
        "\x1c" => '\u001c',
        "\x1d" => '\u001d',
        "\x1e" => '\u001e',
        "\x1f" => '\u001f',
        '"'    => '\"',
        '\\'   => '\\\\',
        "\x7f" => '\u007f',
    ];

    /**
     * Quoted object keys, by ascii flag then key, so a key repeated across records is escaped once.
     *
     * @var array<int, array<array-key, string>>
     */
    private array $keys = [];

    public function encode(mixed $value, EncodeOptions $options): string
    {
        $unit = '';
        if ($options->useTab) {
            $unit = "\t";
        } elseif ($options->indent > 0) {
            $unit = str_repeat(' ', $options->indent);
        }

        $newline = '' === $unit ? '' : "\n";
        if ($options->colors instanceof ColorScheme) {
            return $this->colored($value, $newline, $unit, $options->sortKeys, $options->ascii, $options->colors);
        }

        return $this->plain($value, $newline, $unit, $options->sortKeys, $options->ascii);
    }

    /**
     * @param string $newline the line break plus indentation of the current depth ("" when compact)
     * @param string $unit    one level of indentation ("" when compact)
     */
    private function plain(mixed $value, string $newline, string $unit, bool $sortKeys, bool $ascii, int $depth = 0): string
    {
        if ($depth > self::MAX_PRINT_DEPTH) {
            return self::SKIPPED;
        }

        if (\is_string($value)) {
            return $this->quote($value, $ascii);
        }

        if (\is_int($value)) {
            return (string)$value;
        }

        if (\is_array($value)) {
            if ([] === $value) {
                return '[]';
            }

            $child = $newline . $unit;
            if ($depth >= self::MAX_PRINT_DEPTH) {
                return '[' . $child . implode(',' . $child, array_fill(0, \count($value), self::SKIPPED)) . $newline . ']';
            }

            $parts = [];
            foreach ($value as $item) {
                if (\is_string($item)) {
                    $parts[] = $this->quote($item, $ascii);

                    continue;
                }

                if (\is_int($item)) {
                    $parts[] = (string)$item;

                    continue;
                }

                $parts[] = $this->plain($item, $child, $unit, $sortKeys, $ascii, $depth + 1);
            }

            return '[' . $child . implode(',' . $child, $parts) . $newline . ']';
        }

        if ($value instanceof JsonObject) {
            if (0 === \count($value)) {
                return '{}';
            }

            $child = $newline . $unit;
            $colon = '' === $unit ? ':' : ': ';
            $parts = [];
            foreach ($this->members($value, $sortKeys) as $key => $member) {
                $parts[] = ($this->keys[$ascii][$key] ??= $this->quote((string)$key, $ascii)) . $colon . $this->plain($member, $child, $unit, $sortKeys, $ascii, $depth + 1);
            }

            return '{' . $child . implode(',' . $child, $parts) . $newline . '}';
        }

        return $this->scalar($value);
    }

    private function colored(mixed $value, string $newline, string $unit, bool $sortKeys, bool $ascii, ColorScheme $colors, int $depth = 0): string
    {
        if ($depth > self::MAX_PRINT_DEPTH) {
            return self::SKIPPED;
        }

        if (\is_array($value)) {
            if ([] === $value) {
                return $colors->array . '[]' . self::RESET;
            }

            $child = $newline . $unit;
            $open  = $colors->array . '[' . self::RESET;
            $comma = $colors->array . ',' . self::RESET;
            $out   = $open;
            $first = true;
            foreach ($value as $item) {
                $out  .= ($first ? '' : $comma) . $child . $this->colored($item, $child, $unit, $sortKeys, $ascii, $colors, $depth + 1);
                $first = false;
            }

            return $out . $newline . $colors->array . ']' . self::RESET;
        }

        if ($value instanceof JsonObject) {
            if (0 === \count($value)) {
                return $colors->object . '{}' . self::RESET;
            }

            $child = $newline . $unit;
            $open  = $colors->object . '{' . self::RESET;
            $comma = $colors->object . ',' . self::RESET;
            $colon = $colors->object . ':' . self::RESET . ('' === $unit ? '' : ' ');
            $out   = $open;
            $first = true;
            foreach ($this->members($value, $sortKeys) as $key => $member) {
                $out .= ($first ? '' : $comma) . $child
                    . $colors->objectKey . $this->quote((string)$key, $ascii) . self::RESET
                    . $colon
                    . $this->colored($member, $child, $unit, $sortKeys, $ascii, $colors, $depth + 1);
                $first = false;
            }

            return $out . $newline . $colors->object . '}' . self::RESET;
        }

        $color = match (true) {
            null  === $value        => $colors->null,
            false === $value        => $colors->false,
            true  === $value        => $colors->true,
            \is_string($value)      => $colors->string,
            default                 => $colors->number,
        };

        $text = \is_string($value) ? $this->quote($value, $ascii) : $this->scalar($value);

        return $color . $text . self::RESET;
    }

    /**
     * The members in output order; with sorting, by the bytes of the key (jq sorts by codepoint, which is
     * the same order for UTF-8). Numeric looking keys are ints in PHP's storage, hence the string flag.
     *
     * @return array<array-key, mixed>
     */
    private function members(JsonObject $object, bool $sortKeys): array
    {
        $members = $object->toArray();
        if ($sortKeys) {
            ksort($members, \SORT_STRING);
        }

        return $members;
    }

    /**
     * null, booleans and numbers.
     */
    private function scalar(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (true === $value) {
            return 'true';
        }

        if (false === $value) {
            return 'false';
        }

        if (\is_int($value)) {
            return (string)$value;
        }

        if (\is_float($value)) {
            return NumberFormatter::format($value);
        }

        if ($value instanceof PreciseNumber) {
            return $value->literal;
        }

        throw new InvalidArgumentException('Not a JSON value: ' . get_debug_type($value));
    }

    private function quote(string $text, bool $ascii): string
    {
        // Plain-text test: strcspn plus mb_check_encoding is 1.7x faster per call than the PLAIN_UTF8 regex
        // (micro benchmark over typical short strings; equivalence checked on 475k byte sequences).
        if ($ascii) {
            if (1 === preg_match(self::PLAIN_ASCII, $text)) {
                return '"' . $text . '"';
            }
        } elseif (\strlen($text) === strcspn($text, self::UNSAFE) && mb_check_encoding($text, 'UTF-8')) {
            return '"' . $text . '"';
        }

        $escaped = strtr(Utf8::sanitize($text), self::ESCAPES);
        if ($ascii) {
            $escaped = (string)preg_replace_callback('/[\x{80}-\x{10FFFF}]/u', $this->escapeCodepoint(...), $escaped);
        }

        return '"' . $escaped . '"';
    }

    /**
     * @param array<int|string, string> $match
     */
    private function escapeCodepoint(array $match): string
    {
        $codepoint = Utf8::codepoint($match[0]);
        if ($codepoint < 0x10000) {
            return \sprintf('\u%04x', $codepoint);
        }

        $offset = $codepoint - 0x10000;

        return \sprintf('\u%04x\u%04x', 0xD800 + ($offset >> 10), 0xDC00 + ($offset & 0x3FF));
    }
}
