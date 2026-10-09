<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use RuntimeException;

/**
 * `halt` and `halt_error`: stop the whole program. $stderrText is written to stderr by the CLI before
 * exiting with $exitCode (null for plain `halt`).
 *
 * @internal
 */
final class HaltException extends RuntimeException
{
    public function __construct(
        public readonly int $exitCode,
        public readonly ?string $stderrText = null,
    ) {
        parent::__construct('halt');
    }
}
