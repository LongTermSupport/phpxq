<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Cli;

use LTS\PhpXq\Cli\FrontController;
use LTS\PhpXq\Cli\FrontControllerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FrontControllerTest extends TestCase
{
    /**
     * @param list<string> $args
     */
    #[DataProvider('provideUsageErrors')]
    public function testUsageErrorsExitWithTwo(array $args): void
    {
        [$exit, $stdout, $stderr] = $this->invoke($args);

        self::assertSame(FrontControllerInterface::EXIT_USAGE, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('usage: phpxq', $stderr);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideUsageErrors(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'unknown tool' => [['sed', 's/a/b/']];
    }

    #[DataProvider('provideTools')]
    public function testKnownToolsAreNotImplementedYet(string $tool): void
    {
        [$exit, $stdout, $stderr] = $this->invoke([$tool, '.']);

        self::assertSame(FrontControllerInterface::EXIT_NOT_IMPLEMENTED, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString($tool . ': not implemented', $stderr);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTools(): iterable
    {
        yield 'jq' => ['jq'];
        yield 'yq' => ['yq'];
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function invoke(array $args): array
    {
        $stdin  = fopen('php://memory', 'rb');
        $stdout = fopen('php://memory', 'w+b');
        $stderr = fopen('php://memory', 'w+b');
        self::assertIsResource($stdin);
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = new FrontController()->run($args, $stdin, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);

        return [$exit, (string)stream_get_contents($stdout), (string)stream_get_contents($stderr)];
    }
}
