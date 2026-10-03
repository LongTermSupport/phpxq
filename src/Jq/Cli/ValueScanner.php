<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * Finds the extent of the next top-level JSON value in a text without decoding it, so the CLI knows
 * where each input value ends (for `input_line_number` and error positions) and can hand exactly that
 * slice to the decoder. Containers are matched by bracket depth with strings skipped; a scalar runs to
 * the next whitespace or structural character. The slice is not validated: the decoder does that.
 *
 * @api
 */
final class ValueScanner
{
    /** no further value: only whitespace is left */
    public const int NONE = 0;

    /** a value occupies [$start, $end) */
    public const int FOUND = 1;

    /** a string or container starts at $start and the text ends before it closes */
    public const int INCOMPLETE = 2;

    /** the text at $start cannot begin a value (a stray `]`, `}`, `,` or `:`) */
    public const int INVALID = 3;

    public int $start = 0;

    public int $end = 0;

    /**
     * @return self::NONE|self::FOUND|self::INCOMPLETE|self::INVALID
     */
    public function find(string $text, int $offset): int
    {
        $length = \strlen($text);
        $offset += strspn($text, " \t\r\n", $offset);
        if ($offset >= $length) {
            return self::NONE;
        }

        $this->start = $offset;
        $first       = $text[$offset];

        if ('"' === $first) {
            $end = $this->stringEnd($text, $offset + 1, $length);
            if ($end < 0) {
                return self::INCOMPLETE;
            }

            $this->end = $end;

            return self::FOUND;
        }

        if ('[' === $first || '{' === $first) {
            return $this->container($text, $offset, $length);
        }

        if (']' === $first || '}' === $first || ',' === $first || ':' === $first) {
            return self::INVALID;
        }

        $this->end = $offset + strcspn($text, " \t\r\n[]{},:\"", $offset);

        return self::FOUND;
    }

    /**
     * @return self::FOUND|self::INCOMPLETE
     */
    private function container(string $text, int $offset, int $length): int
    {
        $depth = 1;
        $index = $offset + 1;
        while (true) {
            $index += strcspn($text, '"[]{}', $index);
            if ($index >= $length) {
                return self::INCOMPLETE;
            }

            $char = $text[$index];
            if ('"' === $char) {
                $index = $this->stringEnd($text, $index + 1, $length);
                if ($index < 0) {
                    return self::INCOMPLETE;
                }

                continue;
            }

            ++$index;
            if ('[' === $char || '{' === $char) {
                ++$depth;

                continue;
            }

            --$depth;
            if (0 === $depth) {
                $this->end = $index;

                return self::FOUND;
            }
        }
    }

    /**
     * Offset just past the quote that closes the string whose body starts at $index, or -1 when the
     * text ends first.
     */
    private function stringEnd(string $text, int $index, int $length): int
    {
        while (true) {
            $index += strcspn($text, '"\\', $index);
            if ($index >= $length) {
                return -1;
            }

            if ('"' === $text[$index]) {
                return $index + 1;
            }

            $index += 2;
        }
    }
}
