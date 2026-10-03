<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * One thing read from the inputs: a JSON value, or a parse error. The position (file and line) travels
 * with it, so a look-ahead does not move `input_filename` or the `(at file:line)` of an error message.
 * The line is either known ($line) or worked out on demand from a {@see LineTracker} and the item's
 * ordinal in its text.
 *
 * @api
 */
final readonly class InputItem
{
    private function __construct(
        public mixed $value,
        public ?string $error,
        public bool $fatal,
        public ?string $filename,
        public int $line,
        public ?LineTracker $tracker,
        public int $ordinal,
    ) {
    }

    public static function value(mixed $value, ?string $filename, int $line): self
    {
        return new self($value, null, false, $filename, $line, null, 0);
    }

    /**
     * A value whose line number is only computed if somebody asks for it.
     *
     * @param int $ordinal 1 for the first value of the tracker's text
     */
    public static function tracked(mixed $value, ?string $filename, LineTracker $tracker, int $ordinal): self
    {
        return new self($value, null, false, $filename, 0, $tracker, $ordinal);
    }

    /**
     * @param bool $fatal true when the input cannot be read any further (no `--seq` recovery)
     */
    public static function error(string $message, bool $fatal, ?string $filename, int $line): self
    {
        return new self(null, $message, $fatal, $filename, $line, null, 0);
    }

    public function isError(): bool
    {
        return null !== $this->error;
    }

    public function lineNumber(): int
    {
        return $this->tracker instanceof LineTracker ? $this->tracker->lineOfValue($this->ordinal) : $this->line;
    }
}
