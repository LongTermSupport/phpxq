<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support;

use LTS\PhpXq\Cli\FrontControllerInterface;

/**
 * A front controller that records the arguments it was asked to run and returns a fixed exit code.
 */
final class RecordingFrontController implements FrontControllerInterface
{
    /**
     * @var list<string>|null
     */
    public ?array $received = null;

    public function __construct(private readonly int $exitCode = self::EXIT_OK)
    {
    }

    public function run(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
    {
        $this->received = array_values($args);

        return $this->exitCode;
    }
}
