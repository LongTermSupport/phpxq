<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * One thing read from the inputs: a JSON value, or a parse error. The position (file and line count
 * at the time the item was produced) travels with it, so a look-ahead does not move `input_filename`
 * or the `(at file:line)` of an error message.
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
    ) {
    }

    public static function value(mixed $value, ?string $filename, int $line): self
    {
        return new self($value, null, false, $filename, $line);
    }

    /**
     * @param bool $fatal true when the input cannot be read any further (no `--seq` recovery)
     */
    public static function error(string $message, bool $fatal, ?string $filename, int $line): self
    {
        return new self(null, $message, $fatal, $filename, $line);
    }

    public function isError(): bool
    {
        return null !== $this->error;
    }
}
