<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * Buffered writes to a stream that survive a closed pipe: a failed write marks the writer failed and
 * drops everything after it instead of raising a PHP notice for every output.
 *
 * @api
 */
final class OutputWriter
{
    private string $buffer = '';

    private bool $failed = false;

    private string $reason = 'Broken pipe';

    /**
     * @param resource $stream
     * @param int      $limit  flush once this many bytes are buffered; 0 writes through
     */
    public function __construct(
        private readonly mixed $stream,
        private readonly int $limit = 65536,
    ) {
    }

    public function write(string $bytes): void
    {
        if ($this->failed) {
            return;
        }

        $this->buffer .= $bytes;
        if (\strlen($this->buffer) >= $this->limit) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ('' === $this->buffer) {
            return;
        }

        $data         = $this->buffer;
        $this->buffer = '';
        if ($this->failed) {
            return;
        }

        $length  = \strlen($data);
        $written = 0;
        set_error_handler(function (int $severity, string $message): bool {
            if (1 === preg_match('/errno=\d+ (.+)$/', $message, $matches)) {
                $this->reason = $matches[1];
            }

            return true;
        });

        try {
            while ($written < $length) {
                $chunk = fwrite($this->stream, 0 === $written ? $data : substr($data, $written));
                if (false === $chunk || 0 === $chunk) {
                    $this->failed = true;

                    return;
                }

                $written += $chunk;
            }

            if (!fflush($this->stream)) {
                $this->failed = true;
            }
        } finally {
            restore_error_handler();
        }
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    /**
     * The system's wording for why the last write failed, as jq prints it after "writing output failed:".
     */
    public function failureReason(): string
    {
        return $this->reason;
    }
}
