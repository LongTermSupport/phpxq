<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yq\Format\FormatException;

/**
 * Low-level scanning helpers for HCL text: finding where an expression ends, splitting a bracketed list
 * or object at its top-level commas, and walking over string literals with their `${ ... }` templates.
 */
final readonly class HclScanner
{
    private function __construct()
    {
    }

    /**
     * The offset just past a string literal that starts at `$pos` (which holds the opening quote).
     *
     * @throws FormatException
     */
    public static function skipString(string $text, int $pos): int
    {
        $length = \strlen($text);
        ++$pos;
        while ($pos < $length) {
            $char = $text[$pos];
            if ('\\' === $char) {
                $pos += 2;

                continue;
            }

            if ('"' === $char) {
                return $pos + 1;
            }

            if ("\n" === $char) {
                throw new FormatException('hcl: newline in string literal');
            }

            if (('$' === $char || '%' === $char) && '{' === substr($text, $pos + 1, 1)) {
                if ($pos > 0 && $text[$pos - 1] === $char) {
                    ++$pos;

                    continue;
                }

                $pos = self::skipBraces($text, $pos + 1);

                continue;
            }

            ++$pos;
        }

        throw new FormatException('hcl: unterminated string literal');
    }

    /**
     * The offset just past the `}` that closes the `{` at `$pos`, ignoring braces inside strings.
     *
     * @throws FormatException
     */
    public static function skipBraces(string $text, int $pos): int
    {
        $length = \strlen($text);
        $depth  = 0;
        while ($pos < $length) {
            $char = $text[$pos];
            if ('"' === $char) {
                $pos = self::skipString($text, $pos);

                continue;
            }

            if ('{' === $char) {
                ++$depth;
            } elseif ('}' === $char) {
                --$depth;
                if (0 === $depth) {
                    return $pos + 1;
                }
            }

            ++$pos;
        }

        throw new FormatException('hcl: unbalanced braces');
    }

    /**
     * The offset where the expression starting at `$pos` ends: at a newline or comment outside every
     * bracket, string and heredoc.
     *
     * @throws FormatException
     */
    public static function expressionEnd(string $text, int $pos): int
    {
        $length = \strlen($text);
        $depth  = 0;
        while ($pos < $length) {
            $char = $text[$pos];
            switch (true) {
                case '"' === $char:
                    $pos = self::skipString($text, $pos);

                    continue 2;
                case '(' === $char || '[' === $char || '{' === $char:
                    ++$depth;

                    break;
                case ')' === $char || ']' === $char || '}' === $char:
                    if (0 === $depth) {
                        return $pos;
                    }

                    --$depth;

                    break;
                case '<' === $char && 1 === preg_match('/\G<<-?([A-Za-z_][A-Za-z0-9_]*)[ \t]*\r?\n/', $text, $m, 0, $pos):
                    $end = self::heredocEnd($text, $pos + \strlen($m[0]), $m[1]);
                    $pos = $end;

                    continue 2;
                case "\n" === $char:
                    if (0 === $depth) {
                        return $pos;
                    }

                    break;
                case '#' === $char || ('/' === $char && '/' === substr($text, $pos + 1, 1)):
                    if (0 === $depth) {
                        return $pos;
                    }

                    $pos += strcspn($text, "\n", $pos);

                    continue 2;
                case '/' === $char && '*' === substr($text, $pos + 1, 1):
                    $close = strpos($text, '*/', $pos + 2);
                    $pos   = false === $close ? $length : $close + 2;

                    continue 2;
                default:
                    break;
            }

            ++$pos;
        }

        if ($depth > 0) {
            throw new FormatException('hcl: unbalanced brackets in expression');
        }

        return $pos;
    }

    /**
     * Splits the text between a pair of brackets at its commas (and, when `$newlines` is set, its
     * newlines) that sit outside every nested bracket and string. Empty pieces are dropped.
     *
     * @return list<string>
     */
    public static function splitTop(string $inner, bool $newlines): array
    {
        $parts  = [];
        $start  = 0;
        $depth  = 0;
        $pos    = 0;
        $length = \strlen($inner);
        while ($pos < $length) {
            $char = $inner[$pos];
            if ('"' === $char) {
                $pos = self::skipString($inner, $pos);

                continue;
            }

            if ('(' === $char || '[' === $char || '{' === $char) {
                ++$depth;
            } elseif (')' === $char || ']' === $char || '}' === $char) {
                --$depth;
            } elseif (0 === $depth && (',' === $char || ($newlines && "\n" === $char))) {
                $parts[] = substr($inner, $start, $pos - $start);
                $start   = $pos + 1;
            }

            ++$pos;
        }

        $parts[] = substr($inner, $start);

        return array_values(array_filter(array_map(trim(...), $parts), static fn (string $part): bool => '' !== $part));
    }

    /**
     * Whether the text holds a comment outside its string literals.
     */
    public static function hasComment(string $text): bool
    {
        $pos    = 0;
        $length = \strlen($text);
        while ($pos < $length) {
            $char = $text[$pos];
            if ('"' === $char) {
                $pos = self::skipString($text, $pos);

                continue;
            }

            if ('#' === $char || ('/' === $char && \in_array(substr($text, $pos + 1, 1), ['/', '*'], true))) {
                return true;
            }

            ++$pos;
        }

        return false;
    }

    private static function heredocEnd(string $text, int $pos, string $marker): int
    {
        $length = \strlen($text);
        while ($pos < $length) {
            $lineEnd = $pos + strcspn($text, "\n", $pos);
            if (trim(substr($text, $pos, $lineEnd - $pos)) === $marker) {
                return $lineEnd;
            }

            $pos = $lineEnd + 1;
        }

        throw new FormatException('hcl: unterminated heredoc ' . $marker);
    }
}
