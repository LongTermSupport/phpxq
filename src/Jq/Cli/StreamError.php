<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

/**
 * A parse error met by the {@see StreamParser}: the message, and the path of the value being read
 * when it happened (the `--stream-errors` event is `[message, path]`).
 *
 * @api
 */
final readonly class StreamError
{
    /**
     * @param list<int|string|null> $path
     * @param bool                  $fatal false under `--seq`, where the parser resumes at the next RS
     */
    public function __construct(
        public string $message,
        public array $path,
        public bool $fatal,
    ) {
    }
}
