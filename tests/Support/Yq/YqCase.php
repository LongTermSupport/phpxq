<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Yq;

/**
 * One documented yq example: an invocation, its input document and the documented stdout.
 */
final readonly class YqCase
{
    /**
     * @param list<string> $flags
     */
    public function __construct(
        public string $name,
        public string $source,
        public string $heading,
        public ?string $command,
        public array $flags,
        public ?string $expression,
        public string $input,
        public string $expected,
    ) {
    }

    /**
     * @return array{name: string, source: string, heading: string, command: string|null, flags: list<string>, expression: string|null, input: string, expected: string}
     */
    public function toArray(): array
    {
        return [
            'name'       => $this->name,
            'source'     => $this->source,
            'heading'    => $this->heading,
            'command'    => $this->command,
            'flags'      => $this->flags,
            'expression' => $this->expression,
            'input'      => $this->input,
            'expected'   => $this->expected,
        ];
    }
}
