<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\YqApplication;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BrokenPipeTest extends TestCase
{
    public function testAClosedOutputPipeEndsQuietlyWithTheSigpipeStatus(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$writer, $reader] = $pair;
        fclose($reader);

        $stdin  = fopen('php://memory', 'r+b');
        $stderr = fopen('php://memory', 'w+b');
        self::assertIsResource($stdin);
        self::assertIsResource($stderr);

        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        });

        try {
            $code = new YqApplication()->run($stdin, $writer, $stderr, '-n', '[1, 2, 3]');
        } finally {
            restore_error_handler();
        }

        rewind($stderr);
        self::assertSame(141, $code);
        self::assertSame('', stream_get_contents($stderr));
        self::assertSame([], $notices);
    }
}
