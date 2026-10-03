<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\FileReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
final class JqApplicationFileReaderTest extends TestCase
{
    public function testReadsAFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'jqfr');
        self::assertIsString($path);
        file_put_contents($path, "a\0b");

        try {
            self::assertSame("a\0b", FileReader::read($path));
        } finally {
            unlink($path);
        }
    }

    public function testMissingFile(): void
    {
        $this->expectExceptionObject(new RuntimeException('Could not open /nonexistent/file: No such file or directory'));
        FileReader::read('/nonexistent/file');
    }

    public function testDirectory(): void
    {
        $this->expectExceptionObject(new RuntimeException('Could not open ' . sys_get_temp_dir() . ': Is a directory'));
        FileReader::read(sys_get_temp_dir());
    }

    public function testUnreadableFile(): void
    {
        if (0 === posix_getuid()) {
            self::markTestSkipped('root can read everything');
        }

        $path = tempnam(sys_get_temp_dir(), 'jqfr');
        self::assertIsString($path);
        chmod($path, 0o000);

        try {
            $this->expectExceptionObject(new RuntimeException('Could not open ' . $path . ': Permission denied'));
            FileReader::read($path);
        } finally {
            unlink($path);
        }
    }
}
