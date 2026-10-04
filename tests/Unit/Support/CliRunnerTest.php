<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support;

use LTS\PhpXq\Cli\FrontControllerInterface;
use LTS\PhpXq\Tests\Support\CliRunner;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CliRunnerTest extends TestCase
{
    public function testCapturesExitCodeAndStreams(): void
    {
        $controller = new class implements FrontControllerInterface {
            public function run(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
            {
                fwrite($stdout, 'args='. implode(',', $args) . ' stdin=' . stream_get_contents($stdin));
                fwrite($stderr, 'oops');

                return 7;
            }
        };

        $result = new CliRunner($controller)->run(['jq', '.'], 'input');

        self::assertSame(7, $result->exitCode);
        self::assertSame('args=jq,. stdin=input', $result->stdout);
        self::assertSame('oops', $result->stderr);
    }

    public function testStdinDefaultsToEmpty(): void
    {
        $controller = new class implements FrontControllerInterface {
            public function run(mixed $stdin, mixed $stdout, mixed $stderr, string ...$args): int
            {
                fwrite($stdout, '['. stream_get_contents($stdin) . ']');

                return 0;
            }
        };

        self::assertSame('[]', new CliRunner($controller)->run(['jq'])->stdout);
    }
}
