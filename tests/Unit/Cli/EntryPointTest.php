<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Cli;

use LTS\PhpXq\Cli\EntryPoint;
use LTS\PhpXq\Cli\FrontControllerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class EntryPointTest extends TestCase
{
    /**
     * @param list<string> $args
     * @param list<string> $expected
     */
    #[DataProvider('provideInvocations')]
    public function testToolIsResolvedFromProgramNameOrFirstArgument(string $argv0, array $args, array $expected): void
    {
        $controller = new class implements FrontControllerInterface {
            /** @var list<string> */
            public array $received = [];

            public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
            {
                $this->received = $args;

                return 7;
            }
        };

        $exit = $this->entryPoint($controller)->run($argv0, $args, $this->stream(), $this->stream(), $this->stream());

        self::assertSame(7, $exit);
        self::assertSame($expected, $controller->received);
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function provideInvocations(): iterable
    {
        yield 'phpxq passes args through' => ['/usr/bin/phpxq', ['jq', '.a'], ['jq', '.a']];
        yield 'jq name prepends tool' => ['/usr/local/bin/jq', ['.a'], ['jq', '.a']];
        yield 'yq name prepends tool' => ['yq', ['.a', 'f.yaml'], ['yq', '.a', 'f.yaml']];
        yield 'windows exe suffix' => ['C:\\bin\\jq.exe', ['.'], ['jq', '.']];
        yield 'phar name is not a tool' => ['phpxq.phar', ['yq', '.'], ['yq', '.']];
        yield 'jq name with no args' => ['jq', [], ['jq']];
    }

    public function testVersionFlagPrintsVersionWithoutDispatching(): void
    {
        $controller = new class implements FrontControllerInterface {
            public bool $called = false;

            public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
            {
                $this->called = true;

                return 1;
            }
        };
        $stdout = $this->stream();

        $exit = $this->entryPoint($controller)->run('phpxq', ['--version'], $this->stream(), $stdout, $this->stream());

        rewind($stdout);
        self::assertSame(FrontControllerInterface::EXIT_OK, $exit);
        self::assertSame("phpxq 1.2.3\n", stream_get_contents($stdout));
        self::assertFalse($controller->called);
    }

    public function testVersionFlagIsLeftToTheToolWhenInvokedAsTool(): void
    {
        $controller = new class implements FrontControllerInterface {
            /** @var list<string> */
            public array $received = [];

            public function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
            {
                $this->received = $args;

                return 0;
            }
        };

        $this->entryPoint($controller)->run('jq', ['--version'], $this->stream(), $this->stream(), $this->stream());

        self::assertSame(['jq', '--version'], $controller->received);
    }

    public function testMissingVersionFileReportsUnknown(): void
    {
        $stdout = $this->stream();

        new EntryPoint(new \LTS\PhpXq\Cli\FrontController(), '/nonexistent/VERSION')
            ->run('phpxq', ['--version'], $this->stream(), $stdout, $this->stream());

        rewind($stdout);
        self::assertSame("phpxq unknown\n", stream_get_contents($stdout));
    }

    private function entryPoint(FrontControllerInterface $controller): EntryPoint
    {
        return new EntryPoint($controller, __DIR__ . '/../../Support/Fixtures/VERSION');
    }

    /**
     * @return resource
     */
    private function stream()
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);

        return $stream;
    }
}
