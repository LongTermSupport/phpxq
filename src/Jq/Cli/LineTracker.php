<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * Answers "how many lines had jq read when it produced the Nth value of this text", lazily. The decoder
 * hands out values without positions, and most runs never ask, so the text is only scanned (with the
 * {@see ValueScanner}) when a line number is wanted, and then incrementally: asking for ever later values
 * costs one pass over the text in total.
 *
 * jq feeds its parser line by line, so the count of a value includes the whole line it ended on: for
 * `1\n2\n` the value 1 is on line 1, and `{"a":1}` without a trailing newline is on line 0.
 *
 * @api
 */
final class LineTracker
{
    private readonly ValueScanner $scanner;

    private int $offset = 0;

    private int $values = 0;

    private int $lines = 0;

    private int $counted = 0;

    private int $eol = -1;

    /**
     * @param int $firstLine the number of lines read before $text, when it is a piece of a longer input
     */
    public function __construct(
        private readonly string $text,
        private readonly int $firstLine = 0,
    ) {
        $this->scanner = new ValueScanner();
    }

    /**
     * @param int $ordinal 1 for the first value of the text
     */
    public function lineOfValue(int $ordinal): int
    {
        if ($ordinal < $this->values) {
            $this->offset  = 0;
            $this->values  = 0;
            $this->lines   = 0;
            $this->counted = 0;
            $this->eol     = -1;
        }

        while ($this->values < $ordinal && ValueScanner::FOUND === $this->scanner->find($this->text, $this->offset)) {
            $this->offset = $this->scanner->end;
            ++$this->values;
        }

        if (0 === $this->values) {
            return $this->firstLine;
        }

        $length = \strlen($this->text);
        if ($this->eol < $this->offset) {
            $found     = strpos($this->text, "\n", $this->offset);
            $this->eol = false === $found ? \PHP_INT_MAX : $found;
        }

        $upto = \PHP_INT_MAX === $this->eol ? $length : $this->eol + 1;
        if ($upto > $this->counted) {
            $this->lines  += substr_count($this->text, "\n", $this->counted, $upto - $this->counted);
            $this->counted = $upto;
        }

        return $this->firstLine + $this->lines;
    }
}
