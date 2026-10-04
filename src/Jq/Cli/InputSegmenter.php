<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * Cuts a byte stream that arrives in chunks into segments that can be decoded on their own, so a pipe
 * (`tail -f log | jq .`) is processed as it flows instead of after end-of-file.
 *
 * A segment ends at a newline that falls between two top-level JSON values (or, for raw lines, at any
 * newline), so every value in it is complete and the line count of a value does not depend on text that
 * has not arrived yet. The scan resumes where the last chunk stopped, so a value delivered in many small
 * chunks is still scanned once. Nothing is validated here: a damaged value only has to end somewhere,
 * and the decoder reports it.
 *
 * @api
 */
final class InputSegmenter
{
    private const int BETWEEN = 0;

    private const int SCALAR = 1;

    private const int STRING = 2;

    private const int CONTAINER = 3;

    private const int CONTAINER_STRING = 4;

    private string $buffer = '';

    private int $position = 0;

    private int $state = self::BETWEEN;

    private int $depth = 0;

    private bool $escaped = false;

    /**
     * @param bool $json true to cut between JSON values, false to cut after any newline
     */
    public function __construct(
        private readonly bool $json,
    ) {
    }

    /**
     * Add a chunk; returns the text that is now known to consist of whole values, if any.
     */
    public function push(string $chunk): ?string
    {
        $this->buffer .= $chunk;
        if (!$this->json) {
            $cut = $this->lastNewline();
        } else {
            $cut = $this->wholeLines();
            if (0 === $cut) {
                $cut = $this->scan();
            }
        }

        if ($cut <= 0) {
            return null;
        }

        $segment        = substr($this->buffer, 0, $cut);
        $this->buffer   = substr($this->buffer, $cut);
        $this->position = max(0, $this->position - $cut);

        return $segment;
    }

    /**
     * Whatever is left once the stream has ended; an unfinished value is the decoder's to report.
     */
    public function finish(): string
    {
        $rest           = $this->buffer;
        $this->buffer   = '';
        $this->position = 0;
        $this->state    = self::BETWEEN;

        return $rest;
    }

    /**
     * The fast path for line-delimited JSON, which is what a pipe usually carries: when every line up to
     * the last newline is a whole JSON value, `[line,line,...]` is valid JSON, which PHP can check without
     * a scan in PHP code. Only tried on a fresh, unscanned buffer, so a long value that arrives in many
     * chunks is not re-checked each time. Returns the offset after the last newline, or 0 when the lines
     * are not all whole values (the scan then decides).
     */
    private function wholeLines(): int
    {
        if (0 !== $this->position || self::BETWEEN !== $this->state) {
            return 0;
        }

        $last = strrpos($this->buffer, "\n");
        if (false === $last || $last < 1) {
            return 0;
        }

        return json_validate('[' . str_replace("\n", ',', substr($this->buffer, 0, $last)) . ']') ? $last + 1 : 0;
    }

    private function lastNewline(): int
    {
        $found = strrpos($this->buffer, "\n");

        return false === $found ? 0 : $found + 1;
    }

    /**
     * Advance over the new text; the offset after the last newline seen between values, or 0.
     */
    private function scan(): int
    {
        $text   = $this->buffer;
        $length = \strlen($text);
        $cut    = 0;
        $index  = $this->position;

        while ($index < $length) {
            switch ($this->state) {
                case self::BETWEEN:
                    $char = $text[$index];
                    ++$index;
                    if ("\n" === $char) {
                        $cut = $index;
                    } elseif ('"' === $char) {
                        $this->state = self::STRING;
                    } elseif ('[' === $char || '{' === $char) {
                        $this->state = self::CONTAINER;
                        $this->depth = 1;
                    } elseif (!str_contains(" \t\r]},:", $char)) {
                        $this->state = self::SCALAR;
                    }

                    break;

                case self::SCALAR:
                    $index += strcspn($text, " \t\r\n[]{},:\"", $index);
                    if ($index < $length) {
                        $this->state = self::BETWEEN;
                    }

                    break;

                case self::STRING:
                    if ($this->skipString($text, $index, $length)) {
                        $this->state = self::BETWEEN;
                    }

                    break;

                case self::CONTAINER_STRING:
                    if ($this->skipString($text, $index, $length)) {
                        $this->state = self::CONTAINER;
                    }

                    break;

                default:
                    $index += strcspn($text, '"[]{}', $index);
                    if ($index >= $length) {
                        break;
                    }

                    $char = $text[$index];
                    ++$index;
                    if ('"' === $char) {
                        $this->state = self::CONTAINER_STRING;
                    } elseif ('[' === $char || '{' === $char) {
                        ++$this->depth;
                    } elseif (0 === --$this->depth) {
                        $this->state = self::BETWEEN;
                    }

                    break;
            }
        }

        $this->position = $length;

        return $cut;
    }

    /**
     * Move $index past the quote that closes the string being scanned; false when the text ends first
     * (a backslash at the very end is remembered for the next chunk).
     */
    private function skipString(string $text, int &$index, int $length): bool
    {
        while (true) {
            if ($this->escaped) {
                if ($index >= $length) {
                    return false;
                }

                ++$index;
                $this->escaped = false;

                continue;
            }

            $index += strcspn($text, '"\\', $index);
            if ($index >= $length) {
                return false;
            }

            $char = $text[$index];
            ++$index;
            if ('"' === $char) {
                return true;
            }

            $this->escaped = true;
        }
    }
}
