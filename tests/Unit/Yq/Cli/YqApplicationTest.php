<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\YqApplication;
use LTS\PhpXq\Yq\Cli\YqApplicationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class YqApplicationTest extends TestCase
{
    public function testSkeletonReportsNotImplemented(): void
    {
        $stdin  = fopen('php://memory', 'rb');
        $stdout = fopen('php://memory', 'w+b');
        $stderr = fopen('php://memory', 'w+b');
        self::assertIsResource($stdin);
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = new YqApplication()->run(['.'], $stdin, $stdout, $stderr);

        rewind($stderr);
        self::assertSame(YqApplicationInterface::EXIT_NOT_IMPLEMENTED, $exit);
        self::assertSame("yq: not implemented\n", stream_get_contents($stderr));
    }
}
