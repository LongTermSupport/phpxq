<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * The two output streams of one jq run. Standard output is buffered; anything for standard error first
 * flushes it, so the two interleave the way they were produced.
 *
 * @internal
 */
final readonly class Console
{
    private OutputWriter $out;

    private OutputWriter $err;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(mixed $stdout, mixed $stderr)
    {
        $this->out = new OutputWriter($stdout);
        $this->err = new OutputWriter($stderr, 0);
    }

    public function out(string $bytes): void
    {
        $this->out->write($bytes);
    }

    public function err(string $bytes): void
    {
        $this->out->flush();
        $this->err->write($bytes);
    }

    public function flush(): void
    {
        $this->out->flush();
    }

    public function stdoutFailed(): bool
    {
        return $this->out->hasFailed();
    }

    public function failureReason(): string
    {
        return $this->out->failureReason();
    }
}
