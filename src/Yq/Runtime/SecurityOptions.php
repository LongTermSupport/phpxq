<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

/**
 * The reference's `--security-*` switches.
 *
 * @internal
 */
final readonly class SecurityOptions
{
    public function __construct(
        public bool $enableSystemOperator = false,
        public bool $disableEnvOperators = false,
        public bool $disableFileOperators = false,
    ) {
    }
}
