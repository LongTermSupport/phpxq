<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Bench;

/**
 * A program under measurement. `role` is `subject` (phpxq, as a PHP script or a packaged binary) or
 * `reference` (the real jq or yq); `version` is empty when it could not be determined.
 */
final readonly class Target
{
    public const string ROLE_SUBJECT = 'subject';

    public const string ROLE_REFERENCE = 'reference';

    public function __construct(
        public string $id,
        public string $tool,
        public string $role,
        public string $version,
        public string $command,
    ) {
    }

    /**
     * @return array{id: string, tool: string, role: string, version: string, command: string}
     */
    public function toArray(): array
    {
        return [
            'id'      => $this->id,
            'tool'    => $this->tool,
            'role'    => $this->role,
            'version' => $this->version,
            'command' => $this->command,
        ];
    }
}
