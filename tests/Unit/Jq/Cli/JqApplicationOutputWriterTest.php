<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\Console;
use LTS\PhpXq\Jq\Cli\OutputWriter;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationOutputWriterTest extends TestCase
{
    public function testWritesAreBufferedUntilTheLimit(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $writer = new OutputWriter($stream, 8);

        $writer->write('abc');
        self::assertSame('', $this->contents($stream));

        $writer->write('defgh');
        self::assertSame('abcdefgh', $this->contents($stream));
    }

    public function testFlushWritesWhatIsBuffered(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $writer = new OutputWriter($stream);

        $writer->write('x');
        $writer->flush();
        $writer->flush();

        self::assertSame('x', $this->contents($stream));
        self::assertFalse($writer->hasFailed());
    }

    public function testLimitZeroWritesThrough(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $writer = new OutputWriter($stream, 0);

        $writer->write('now');

        self::assertSame('now', $this->contents($stream));
    }

    public function testAClosedPipeMarksTheWriterFailedWithoutNoise(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$near, $far] = $pair;
        fclose($far);

        $writer = new OutputWriter($near, 0);
        $writer->write('lost');
        $writer->write('ignored');
        $writer->flush();

        self::assertTrue($writer->hasFailed());
        fclose($near);
    }

    public function testAFullDiskIsReportedWithItsReason(): void
    {
        if (!is_writable('/dev/full')) {
            self::markTestSkipped('/dev/full is not available');
        }

        $stream = fopen('/dev/full', 'wb');
        self::assertIsResource($stream);
        $writer = new OutputWriter($stream);

        $writer->write('data');
        $writer->flush();

        self::assertTrue($writer->hasFailed());
        self::assertSame('No space left on device', $writer->failureReason());
        fclose($stream);
    }

    public function testBrokenPipeIsTheDefaultReason(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);

        self::assertSame('Broken pipe', new OutputWriter($stream)->failureReason());
    }

    public function testConsoleFlushesStdoutBeforeStderr(): void
    {
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $console = new Console($out, $err);

        $console->out('one');
        self::assertSame('', $this->contents($out));

        $console->err('two');

        self::assertSame('one', $this->contents($out));
        self::assertSame('two', $this->contents($err));
        self::assertFalse($console->stdoutFailed());
    }

    public function testConsoleFlushAndFailure(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$near, $far] = $pair;
        fclose($far);
        $err     = fopen('php://memory', 'w+b');
        self::assertIsResource($err);
        $console = new Console($near, $err);

        $console->out('data');
        $console->flush();

        self::assertTrue($console->stdoutFailed());
        fclose($near);
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
