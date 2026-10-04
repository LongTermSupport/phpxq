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

    public function testYqIsDelegatedToTheYqApplication(): void
    {
        [$exit, $stdout, $stderr] = $this->invoke(['yq', '--version']);

        self::assertSame(FrontControllerInterface::EXIT_OK, $exit);
        self::assertStringStartsWith('yq (https://github.com/mikefarah/yq/) version v', $stdout);
        self::assertSame('', $stderr);
    }

    public function testTheCallersErrorHandlerIsRestoredAfterARun(): void
    {
        $sentinel = static fn (): bool => true;
        set_error_handler($sentinel);

        try {
            $this->invoke(['yq', '--version']);
            $current = set_error_handler(static fn (): bool => true);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        self::assertSame($sentinel, $current);
    }

    public function testNoticesDuringARunAreNotShownToTheUser(): void
    {
        $before = \ini_get('display_errors');

        $this->invoke(['yq', '--version']);

        self::assertSame($before, \ini_get('display_errors'));
    }

    public function testUncaughtErrorsBecomeAnInternalErrorExit(): void
    {
        $stdin  = fopen('php://memory', 'rb');
        $stdout = fopen('php://memory', 'w+b');
        $stderr = fopen('php://memory', 'w+b');
        self::assertIsResource($stdin);
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        fclose($stdout);

        $exit = new FrontController()->run(['jq', '-n', '1'], $stdin, $stdout, $stderr);

        rewind($stderr);

        self::assertSame(FrontControllerInterface::EXIT_INTERNAL, $exit);
        self::assertStringStartsWith('jq: error (at <unknown>): internal error: ', (string)stream_get_contents($stderr));
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
