<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Cli;

use LTS\PhpXq\Cli\EntryPoint;
use LTS\PhpXq\Cli\FrontController;
use LTS\PhpXq\Cli\FrontControllerInterface;
use LTS\PhpXq\Cli\ToolEnum;
use LTS\PhpXq\Tests\Support\RecordingFrontController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class EntryPointTest extends TestCase
{
    private const string VERSION_FIXTURE = __DIR__ . '/../../Support/Fixtures/VERSION';

    /**
     * @param list<string> $args
     * @param list<string> $expected
     */
    #[DataProvider('provideInvocations')]
    public function testToolIsResolvedFromProgramNameOrFirstArgument(string $argv0, array $args, array $expected): void
    {
        $controller = new RecordingFrontController(7);

        $exit = new EntryPoint($controller, self::VERSION_FIXTURE)
            ->run($argv0, $this->stream(), $this->stream(), $this->stream(), ...$args)
        ;

        self::assertSame(7, $exit);
        self::assertSame($expected, $controller->received);
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function provideInvocations(): iterable
    {
        $jq = ToolEnum::Jq->value;
        $yq = ToolEnum::Yq->value;

        yield 'phpxq passes args through' => ['/usr/bin/phpxq', [$jq, '.a'], [$jq, '.a']];
        yield 'jq name prepends tool' => ["/usr/local/bin/{$jq}", ['.a'], [$jq, '.a']];
        yield 'yq name prepends tool' => [$yq, ['.a', 'f.yaml'], [$yq, '.a', 'f.yaml']];
        yield 'windows exe suffix' => ["C:\\bin\\{$jq}.exe", ['.'], [$jq, '.']];
        yield 'phar name is not a tool' => ['phpxq.phar', [$yq, '.'], [$yq, '.']];
        yield 'jq name with no args' => [$jq, [], [$jq]];
    }

    public function testVersionFlagPrintsVersionWithoutDispatching(): void
    {
        $controller = new RecordingFrontController();
        $stdout     = $this->stream();

        $exit = new EntryPoint($controller, self::VERSION_FIXTURE)
            ->run('phpxq', $this->stream(), $stdout, $this->stream(), '--version')
        ;

        self::assertSame(FrontControllerInterface::EXIT_OK, $exit);
        self::assertSame(
            "phpxq 1.2.3\njq-1.8.2 compatible (jq)\nyq v4.54.1 compatible (yq)\n",
            $this->contents($stdout),
        );
        self::assertNull($controller->received);
    }

    public function testVersionFlagIsLeftToTheToolWhenInvokedAsTool(): void
    {
        $controller = new RecordingFrontController();

        new EntryPoint($controller, self::VERSION_FIXTURE)
            ->run(ToolEnum::Jq->value, $this->stream(), $this->stream(), $this->stream(), '--version')
        ;

        self::assertSame([ToolEnum::Jq->value, '--version'], $controller->received);
    }

    public function testMissingVersionFileReportsUnknown(): void
    {
        $stdout = $this->stream();

        new EntryPoint(new FrontController(), '/nonexistent/VERSION')
            ->run('phpxq', $this->stream(), $stdout, $this->stream(), '--version')
        ;

        self::assertStringStartsWith("phpxq unknown\n", $this->contents($stdout));
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

    /**
     * @param resource $stream
     */
    private function contents(mixed $stream): string
    {
        rewind($stream);

        return (string)stream_get_contents($stream);
    }
}
