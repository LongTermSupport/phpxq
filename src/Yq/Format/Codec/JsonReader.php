<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\FormatException;

/**
 * A byte-scanning reader that pulls one JSON value at a time out of a string holding any number of
 * concatenated values (JSON lines and pretty-printed streams alike). Numbers keep their source text.
 */
final class JsonReader
{
    private const int MAX_DEPTH = 1000;

    /** Bytes that end a run of plain string characters: `"`, `\` and the control characters. */
    private const string STRING_STOP = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

    private int $pos = 0;

    private readonly int $length;

    public function __construct(private readonly string $source)
    {
        $this->length = \strlen($source);
        if (str_starts_with($source, "\u{FEFF}")) {
            $this->pos = 3;
        }
    }

    /**
     * The next value, or null at the end of the input.
     *
     * @throws FormatException
     */
    public function next(): ?Node
    {
        $this->skipWhitespace();
        if ($this->pos >= $this->length) {
            return null;
        }

        return $this->value(0);
    }

    private function value(int $depth): Node
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('json: exceeded max depth');
        }

        $char = $this->source[$this->pos];

        return match (true) {
            '{' === $char                                   => $this->object($depth),
            '[' === $char                                   => $this->array($depth),
            '"' === $char                                   => new Node(NodeKind::Scalar, CoreSchema::TAG_STR, NodeStyle::Default, $this->string()),
            '-' === $char || ($char >= '0' && $char <= '9') => $this->number(),
            default                                         => $this->literal(),
        };
    }

    private function object(int $depth): Node
    {
        ++$this->pos;
        $keys   = [];
        $values = [];
        $index  = [];
        $this->skipWhitespace();
        if ('}' === $this->peek()) {
            ++$this->pos;

            return Node::mapping();
        }

        while (true) {
            $this->skipWhitespace();
            if ('"' !== $this->peek()) {
                throw $this->unexpected('looking for beginning of object key string');
            }

            $key = $this->string();
            $this->skipWhitespace();
            if (':' !== $this->peek()) {
                throw $this->unexpected('after object key');
            }

            ++$this->pos;
            $this->skipWhitespace();
            if ($this->pos >= $this->length) {
                throw new FormatException('json: unexpected end of JSON input');
            }

            $value = $this->value($depth + 1);
            if (isset($index[$key])) {
                $values[$index[$key]] = $value;
            } else {
                $index[$key] = \count($keys);
                $keys[]      = new Node(NodeKind::Scalar, CoreSchema::TAG_STR, NodeStyle::Default, $key);
                $values[]    = $value;
            }

            $this->skipWhitespace();
            $char = $this->peek();
            ++$this->pos;
            if (',' === $char) {
                continue;
            }

            if ('}' === $char) {
                $content = [];
                foreach ($keys as $position => $keyNode) {
                    $content[] = $keyNode;
                    $content[] = $values[$position];
                }

                return Node::mapping($content);
            }

            --$this->pos;

            throw $this->unexpected('after object key:value pair');
        }
    }

    private function array(int $depth): Node
    {
        ++$this->pos;
        $items = [];
        $this->skipWhitespace();
        if (']' === $this->peek()) {
            ++$this->pos;

            return Node::sequence();
        }

        while (true) {
            $this->skipWhitespace();
            if ($this->pos >= $this->length) {
                throw new FormatException('json: unexpected end of JSON input');
            }

            $items[] = $this->value($depth + 1);
            $this->skipWhitespace();
            $char = $this->peek();
            ++$this->pos;
            if (',' === $char) {
                continue;
            }

            if (']' === $char) {
                return Node::sequence($items);
            }

            --$this->pos;

            throw $this->unexpected('after array element');
        }
    }

    private function number(): Node
    {
        $source = $this->source;
        $start  = $this->pos;
        $i      = $start;
        if ('-' === $source[$i]) {
            ++$i;
        }

        $digits = strspn($source, '0123456789', $i);
        if (0 === $digits) {
            $this->pos = $i;

            throw $this->unexpected('in numeric literal');
        }

        if ($digits > 1 && '0' === $source[$i]) {
            $this->pos = $i + 1;

            throw $this->unexpected('after top-level value');
        }

        $i += $digits;
        $float = false;
        if ('.' === ($source[$i] ?? '')) {
            $fraction = strspn($source, '0123456789', $i + 1);
            if (0 === $fraction) {
                $this->pos = $i + 1;

                throw $this->unexpected('after decimal point in numeric literal');
            }

            $i += 1 + $fraction;
            $float = true;
        }

        if ('e' === ($source[$i] ?? '') || 'E' === ($source[$i] ?? '')) {
            $j = $i + 1;
            if ('+' === ($source[$j] ?? '') || '-' === ($source[$j] ?? '')) {
                ++$j;
            }

            $exponent = strspn($source, '0123456789', $j);
            if (0 === $exponent) {
                $this->pos = $j;

                throw $this->unexpected('in exponent of numeric literal');
            }

            $i += $j - $i + $exponent;
            $float = true;
        }

        $this->pos = $i;
        if ($i < $this->length && str_contains('0123456789.eE+-', $source[$i])) {
            throw $this->unexpected('after top-level value');
        }

        return new Node(NodeKind::Scalar, $float ? CoreSchema::TAG_FLOAT : CoreSchema::TAG_INT, NodeStyle::Default, substr($source, $start, $i - $start));
    }

    private function literal(): Node
    {
        foreach (['true' => CoreSchema::TAG_BOOL, 'false' => CoreSchema::TAG_BOOL, 'null' => CoreSchema::TAG_NULL] as $word => $tag) {
            if (0 === substr_compare($this->source, $word, $this->pos, \strlen($word))) {
                $this->pos += \strlen($word);

                return Node::scalar($word, $tag);
            }
        }

        throw $this->unexpected('looking for beginning of value');
    }

    private function string(): string
    {
        ++$this->pos;
        $run = strcspn($this->source, self::STRING_STOP, $this->pos);
        if ($this->pos + $run < $this->length && '"' === $this->source[$this->pos + $run]) {
            $plain = substr($this->source, $this->pos, $run);
            $this->pos += $run + 1;

            return $plain;
        }

        $out = '';
        while (true) {
            $run = strcspn($this->source, self::STRING_STOP, $this->pos);
            if ($run > 0) {
                $out .= substr($this->source, $this->pos, $run);
                $this->pos += $run;
            }

            if ($this->pos >= $this->length) {
                throw new FormatException('json: unexpected end of JSON input');
            }

            $char = $this->source[$this->pos];
            if ('"' === $char) {
                ++$this->pos;

                return $out;
            }

            if ('\\' !== $char) {
                throw $this->unexpected('in string literal');
            }

            $out .= $this->escape();
        }
    }

    private function escape(): string
    {
        $next = $this->source[$this->pos + 1] ?? '';
        $this->pos += 2;

        switch ($next) {
            case '"':
                return '"';
            case '\\':
                return '\\';
            case '/':
                return '/';
            case 'b':
                return "\x08";
            case 'f':
                return "\x0c";
            case 'n':
                return "\n";
            case 'r':
                return "\r";
            case 't':
                return "\t";
            case 'u':
                return $this->unicodeEscape();
            default:
                --$this->pos;

                throw $this->unexpected('in string escape code');
        }
    }

    private function unicodeEscape(): string
    {
        $code = $this->hex4();
        if ($code >= 0xD800 && $code <= 0xDBFF) {
            if ('\u' === substr($this->source, $this->pos, 2)) {
                $save = $this->pos;
                $this->pos += 2;
                $low = $this->hex4();
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    return mb_chr(0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00), 'UTF-8');
                }

                $this->pos = $save;
            }

            return "\u{FFFD}";
        }

        if ($code >= 0xDC00 && $code <= 0xDFFF) {
            return "\u{FFFD}";
        }

        return mb_chr($code, 'UTF-8');
    }

    private function hex4(): int
    {
        $digits = substr($this->source, $this->pos, 4);
        if (4 !== \strlen($digits) || 4 !== strspn($digits, '0123456789abcdefABCDEF')) {
            throw $this->unexpected('in \u hexadecimal character escape');
        }

        $this->pos += 4;

        return (int)hexdec($digits);
    }

    private function skipWhitespace(): void
    {
        $this->pos += strspn($this->source, " \t\r\n", $this->pos);
    }

    private function peek(): string
    {
        return $this->source[$this->pos] ?? '';
    }

    private function unexpected(string $context): FormatException
    {
        if ($this->pos >= $this->length) {
            return new FormatException('json: unexpected end of JSON input');
        }

        return new FormatException(\sprintf("json: invalid character '%s' %s", $this->source[$this->pos], $context));
    }
}
