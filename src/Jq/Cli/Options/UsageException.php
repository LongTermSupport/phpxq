<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli\Options;

use RuntimeException;

/**
 * A command line jq refuses, with the exit status 2 the real jq uses. The message is printed to stderr
 * as is. When $showShortUsage is set the short usage block follows it (a missing program); otherwise the
 * "Use jq --help ..." pointer does (every other refusal).
 *
 * @api
 */
final class UsageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $showShortUsage = false,
    ) {
        parent::__construct($message);
    }
}
