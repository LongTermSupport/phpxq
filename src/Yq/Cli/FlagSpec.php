<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * One flag of the reference command line.
 *
 * `valueName` is the placeholder shown in the help text (`string`, `int`, `char`) and `defaultText` the
 * rendering of the default shown as `(default ...)`, empty when the help shows none.
 */
final readonly class FlagSpec
{
    public function __construct(
        public string $name,
        public string $short,
        public FlagType $type,
        public bool|int|string $default,
        public string $usage,
        public string $valueName = '',
        public string $defaultText = '',
        public bool $hidden = false,
    ) {
    }

    /**
     * The `-x, --name` form used in error messages.
     */
    public function label(): string
    {
        return '' === $this->short ? '--' . $this->name : '-' . $this->short . ', --' . $this->name;
    }
}
