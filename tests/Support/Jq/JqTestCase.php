<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Jq;

/**
 * One case from an upstream jq `.test` file.
 */
final readonly class JqTestCase
{
    /**
     * @param list<string> $expectedOutputs      one JSON text per expected output, empty for a %%FAIL case
     * @param list<string> $expectedMessageLines the expected compile error text of a %%FAIL case
     */
    public function __construct(
        public string $program,
        public string $input,
        public array $expectedOutputs,
        public bool $shouldFail,
        public bool $failIgnoreMessage,
        public array $expectedMessageLines,
        public string $sourceFile,
        public int $sourceLine,
    ) {
    }

    public function name(): string
    {
        return $this->sourceFile . ':' . $this->sourceLine;
    }
}
