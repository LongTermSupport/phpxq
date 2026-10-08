<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\FormatException;

/**
 * Reads the data subset of Lua that configuration files use: an optional `return`, table constructors,
 * strings, numbers, booleans, `nil`, `(1/0)` style infinities and `name = value` global assignments.
 * Comments are skipped. A table with only positional fields is a sequence, any other table a mapping.
 */
final class LuaReader
{
    private const int MAX_DEPTH = 500;

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
     * @throws FormatException
     */
    public function read(): Node
    {
        $this->skip();
        if ($this->word('return')) {
            $value = $this->expression(0);
            $this->skip();
            if (';' === substr($this->source, $this->pos, 1)) {
                ++$this->pos;
            }

            $this->skip();
            if ($this->pos < $this->length) {
                throw $this->error('unexpected text after return value');
            }

            return $value;
        }

        $content = [];
        while ($this->pos < $this->length) {
            $name = $this->identifier();
            $this->skip();
            if ('=' !== substr($this->source, $this->pos, 1)) {
                throw $this->error('expected = after ' . $name);
            }

            ++$this->pos;
            $content[] = Node::scalar($name, CoreSchema::TAG_STR);
            $content[] = $this->expression(0);
            $this->skip();
            if (';' === substr($this->source, $this->pos, 1)) {
                ++$this->pos;
            }

            $this->skip();
        }

        return Node::mapping($content);
    }

    private function expression(int $depth): Node
    {
        $this->skip();
        $left = $this->unary($depth);
        $this->skip();
        while ('/' === substr($this->source, $this->pos, 1)) {
            ++$this->pos;
            $right = $this->unary($depth);
            $left  = $this->divide($left, $right);
            $this->skip();
        }

        return $left;
    }

    private function divide(Node $left, Node $right): Node
    {
        if (!is_numeric($left->value) || !is_numeric($right->value)) {
            throw $this->error('cannot divide these values');
        }

        $numerator   = (float)$left->value;
        $denominator = (float)$right->value;
        if (0.0 === $denominator) {
            if (0.0 === $numerator) {
                return Node::scalar('.nan', CoreSchema::TAG_FLOAT);
            }

            return Node::scalar($numerator < 0 ? '-.inf' : '.inf', CoreSchema::TAG_FLOAT);
        }

        return Node::scalar((string)($numerator / $denominator), CoreSchema::TAG_FLOAT);
    }

    private function unary(int $depth): Node
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->error('nesting is too deep');
        }

        $this->skip();
        $char = substr($this->source, $this->pos, 1);
        switch (true) {
            case '' === $char:
                throw $this->error('unexpected end of input');
            case '-' === $char:
                ++$this->pos;
                $operand = $this->unary($depth + 1);
                if (CoreSchema::TAG_STR === $operand->tag || !is_numeric($operand->value) && !str_contains($operand->value, 'inf')) {
                    throw $this->error('cannot negate this value');
                }

                $text = str_starts_with($operand->value, '-') ? substr($operand->value, 1) : '-' . $operand->value;

                return Node::scalar($text, $operand->tag);
            case '(' === $char:
                ++$this->pos;
                $inner = $this->expression($depth + 1);
                $this->skip();
                if (')' !== substr($this->source, $this->pos, 1)) {
                    throw $this->error('expected )');
                }

                ++$this->pos;

                return $inner;
            case '{' === $char:
                return $this->table($depth);
            case '"' === $char || "'" === $char:
                return Node::scalar($this->quoted($char), CoreSchema::TAG_STR);
            case '[' === $char:
                return Node::scalar($this->longString(), CoreSchema::TAG_STR);
            case '0' <= $char && '9' >= $char || '.' === $char:
                return $this->number();
            default:
                return $this->name();
        }
    }

    private function name(): Node
    {
        $name = $this->identifier();

        return match ($name) {
            'true', 'false' => Node::scalar($name, CoreSchema::TAG_BOOL),
            'nil'           => Node::scalar('null', CoreSchema::TAG_NULL),
            default         => throw $this->error('unsupported expression ' . $name),
        };
    }

    private function number(): Node
    {
        if (1 === preg_match('/\G0[xX][0-9a-fA-F]+/', $this->source, $m, 0, $this->pos)) {
            $this->pos += \strlen($m[0]);

            return Node::scalar(strtolower($m[0]), CoreSchema::TAG_INT);
        }

        if (1 !== preg_match('/\G(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][-+]?[0-9]+)?/', $this->source, $m, 0, $this->pos)) {
            throw $this->error('malformed number');
        }

        $this->pos += \strlen($m[0]);

        return Node::scalar($m[0], 1 === preg_match('/[.eE]/', $m[0]) ? CoreSchema::TAG_FLOAT : CoreSchema::TAG_INT);
    }

    private function table(int $depth): Node
    {
        ++$this->pos;
        $keys     = [];
        $values   = [];
        $keyed    = false;
        $position = 0;
        while (true) {
            $this->skip();
            $char = substr($this->source, $this->pos, 1);
            if ('' === $char) {
                throw $this->error('unterminated table');
            }

            if ('}' === $char) {
                ++$this->pos;

                break;
            }

            if ('[' === $char && !$this->isLongString()) {
                ++$this->pos;
                $key = $this->expression($depth + 1);
                $this->skip();
                if (']' !== substr($this->source, $this->pos, 1)) {
                    throw $this->error('expected ]');
                }

                ++$this->pos;
                $this->expect('=');
                $keys[]   = $key;
                $values[] = $this->expression($depth + 1);
                $keyed    = true;
            } elseif (1 === preg_match('/\G[A-Za-z_][A-Za-z0-9_]*\s*=(?!=)/', $this->source, $m, 0, $this->pos)) {
                $name = $this->identifier();
                $this->expect('=');
                $keys[]   = Node::scalar($name, CoreSchema::TAG_STR);
                $values[] = $this->expression($depth + 1);
                $keyed    = true;
            } else {
                ++$position;
                $keys[]   = Node::scalar((string)$position, CoreSchema::TAG_INT);
                $values[] = $this->expression($depth + 1);
            }

            $this->skip();
            $separator = substr($this->source, $this->pos, 1);
            if (',' === $separator || ';' === $separator) {
                ++$this->pos;
            } elseif ('}' !== $separator) {
                throw $this->error('expected , or } in table');
            }
        }

        if (!$keyed) {
            return Node::sequence($values);
        }

        $content = [];
        foreach ($keys as $index => $key) {
            $content[] = $key;
            $content[] = $values[$index];
        }

        return Node::mapping($content);
    }

    private function expect(string $char): void
    {
        $this->skip();
        if ($char !== substr($this->source, $this->pos, 1)) {
            throw $this->error('expected ' . $char);
        }

        ++$this->pos;
    }

    private function identifier(): string
    {
        $this->skip();
        if (1 !== preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $this->source, $m, 0, $this->pos)) {
            throw $this->error('expected a name');
        }

        $this->pos += \strlen($m[0]);

        return $m[0];
    }

    private function word(string $word): bool
    {
        if (1 === preg_match('/\G' . $word . '(?![A-Za-z0-9_])/', $this->source, $m, 0, $this->pos)) {
            $this->pos += \strlen($word);

            return true;
        }

        return false;
    }

    private function isLongString(): bool
    {
        return 1 === preg_match('/\G\[=*\[/', $this->source, $m, 0, $this->pos);
    }

    private function longString(): string
    {
        if (1 !== preg_match('/\G\[(=*)\[/', $this->source, $m, 0, $this->pos)) {
            throw $this->error('unexpected [');
        }

        $close = ']' . $m[1] . ']';
        $start = $this->pos + \strlen($m[0]);
        $end   = strpos($this->source, $close, $start);
        if (false === $end) {
            throw $this->error('unterminated long string');
        }

        $this->pos = $end + \strlen($close);
        $text      = substr($this->source, $start, $end - $start);
        if (str_starts_with($text, "\r\n")) {
            return substr($text, 2);
        }

        return str_starts_with($text, "\n") ? substr($text, 1) : $text;
    }

    private function quoted(string $quote): string
    {
        ++$this->pos;
        $out = '';
        while (true) {
            $run = strcspn($this->source, $quote . "\\\n", $this->pos);
            $out .= substr($this->source, $this->pos, $run);
            $this->pos += $run;
            $char = substr($this->source, $this->pos, 1);
            if ($char === $quote) {
                ++$this->pos;

                return $out;
            }

            if ('\\' !== $char) {
                throw $this->error('unterminated string');
            }

            $out .= $this->escape();
        }
    }

    private function escape(): string
    {
        $next = substr($this->source, $this->pos + 1, 1);
        $this->pos += 2;
        switch ($next) {
            case 'a':
                return "\x07";
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
            case 'v':
                return "\x0b";
            case '\\':
            case '"':
            case "'":
            case "\n":
                return $next;
            case 'x':
                $hex = substr($this->source, $this->pos, 2);
                if (2 !== strspn($hex, '0123456789abcdefABCDEF')) {
                    throw $this->error('invalid \x escape');
                }

                $this->pos += 2;

                return \chr((int)hexdec($hex) & 0xFF);
            case 'z':
                $this->pos += strspn($this->source, " \t\r\n", $this->pos);

                return '';
            case 'u':
                if (1 !== preg_match('/\G\{([0-9a-fA-F]+)\}/', $this->source, $m, 0, $this->pos)) {
                    throw $this->error('invalid \u escape');
                }

                $code = hexdec($m[1]);
                $char = \is_int($code) ? mb_chr($code, 'UTF-8') : false;
                if (false === $char) {
                    throw $this->error('invalid \u escape');
                }

                $this->pos += \strlen($m[0]);

                return $char;
            default:
                if ($next >= '0' && $next <= '9') {
                    --$this->pos;
                    if (1 === preg_match('/\G[0-9]{1,3}/', $this->source, $m, 0, $this->pos)) {
                        $this->pos += \strlen($m[0]);

                        return \chr((int)$m[0] & 0xFF);
                    }
                }

                throw $this->error('invalid escape sequence \\' . $next);
        }
    }

    /**
     * Whitespace and comments (`-- line` and `--[[ block ]]`).
     */
    private function skip(): void
    {
        while ($this->pos < $this->length) {
            $this->pos += strspn($this->source, " \t\r\n", $this->pos);
            if ('--' !== substr($this->source, $this->pos, 2)) {
                return;
            }

            $this->pos += 2;
            if (1 === preg_match('/\G\[(=*)\[/', $this->source, $m, 0, $this->pos)) {
                $close     = ']' . $m[1] . ']';
                $end       = strpos($this->source, $close, $this->pos);
                $this->pos = false === $end ? $this->length : $end + \strlen($close);

                continue;
            }

            $this->pos += strcspn($this->source, "\n", $this->pos);
        }
    }

    private function error(string $message): FormatException
    {
        return new FormatException(\sprintf('lua: line %d: %s', substr_count($this->source, "\n", 0, min($this->pos, $this->length)) + 1, $message));
    }
}
