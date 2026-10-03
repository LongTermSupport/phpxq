<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Cli;

use ErrorException;
use LTS\PhpXq\Cli\ErrorGuard;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ErrorGuardTest extends TestCase
{
    private int $reporting = 0;

    protected function setUp(): void
    {
        $this->reporting = error_reporting(\E_ALL);
    }

    protected function tearDown(): void
    {
        error_reporting($this->reporting);
    }

    public function testWarningsBecomeErrorExceptions(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('boom');

        ErrorGuard::raise(\E_WARNING, 'boom', 'file.php', 12);
    }

    public function testSeverityAndLocationAreKept(): void
    {
        try {
            ErrorGuard::raise(\E_DEPRECATED, 'old', 'file.php', 12);
            self::fail('no exception');
        } catch (ErrorException $errorException) {
            self::assertSame(\E_DEPRECATED, $errorException->getSeverity());
            self::assertSame('file.php', $errorException->getFile());
            self::assertSame(12, $errorException->getLine());
        }
    }

    public function testSilencedErrorsAreLeftToPhp(): void
    {
        $previous = error_reporting(0);

        try {
            self::assertFalse(ErrorGuard::raise(\E_WARNING, 'quiet', 'file.php', 1));
        } finally {
            error_reporting($previous);
        }
    }

    public function testFatalErrorIsDescribedWithoutPathOrLine(): void
    {
        $message = ErrorGuard::describeFatal([
            'type'    => \E_ERROR,
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
            'file'    => '/some/where/Thing.php',
            'line'    => 77,
        ]);

        self::assertSame('Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', $message);
    }

    public function testNonFatalLastErrorIsNotDescribed(): void
    {
        self::assertNull(ErrorGuard::describeFatal(['type' => \E_WARNING, 'message' => 'w', 'file' => 'f', 'line' => 1]));
        self::assertNull(ErrorGuard::describeFatal(null));
    }

    public function testFatalMessageIsFirstLineOnly(): void
    {
        $message = ErrorGuard::describeFatal([
            'type'    => \E_ERROR,
            'message' => "Uncaught Error: nope in /x.php:3\nStack trace:\n#0 {main}",
            'file'    => '/x.php',
            'line'    => 3,
        ]);

        self::assertSame('Uncaught Error: nope in /x.php:3', $message);
    }
}
