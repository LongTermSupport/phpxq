<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\JqApplication;
use LTS\PhpXq\Jq\Cli\JqExitCode;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationTest extends TestCase
{
    public function testSkeletonReportsNotImplemented(): void
    {
        $stdin  = fopen('php://memory', 'rb');
        $stdout = fopen('php://memory', 'w+b');
        $stderr = fopen('php://memory', 'w+b');
        self::assertIsResource($stdin);
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = JqApplication::create()->run(['.'], $stdin, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        self::assertSame(JqExitCode::NOT_IMPLEMENTED, $exit);
        self::assertSame('', stream_get_contents($stdout));
        self::assertSame("jq: not implemented\n", stream_get_contents($stderr));
    }
}
