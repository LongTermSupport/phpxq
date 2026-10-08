<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Qa;

use LTS\PhpXq\Qa\FileChange;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parsing of `git diff -z --name-status -M`: NUL-separated records, one path per record or two for a rename or copy.
 *
 * @internal
 */
#[CoversNothing]
final class FileChangeTest extends TestCase
{
    public function testParsesPlainRecords(): void
    {
        self::assertEquals(
            [new FileChange('M', 'src/Yaml/Node.php'), new FileChange('D', 'src/Gone.php')],
            FileChange::parseNameStatusZ("M\0src/Yaml/Node.php\0D\0src/Gone.php\0"),
        );
    }

    public function testARenameYieldsTheOldPathAsADeletionAndTheNewPathWithItsStatus(): void
    {
        self::assertEquals(
            [new FileChange('D', 'tests/Unit/Old/ATest.php'), new FileChange('R', 'tests/Unit/Yaml/NodeTest.php')],
            FileChange::parseNameStatusZ("R087\0tests/Unit/Old/ATest.php\0tests/Unit/Yaml/NodeTest.php\0"),
        );
    }

    public function testACopyKeepsTheSourceAndAddsTheCopy(): void
    {
        self::assertEquals(
            [new FileChange('D', 'src/A.php'), new FileChange('C', 'src/B.php')],
            FileChange::parseNameStatusZ("C100\0src/A.php\0src/B.php\0"),
        );
    }

    /**
     * @param list<FileChange> $expected
     */
    #[DataProvider('unusualPaths')]
    public function testPathsAreTakenVerbatim(string $output, array $expected): void
    {
        self::assertEquals($expected, FileChange::parseNameStatusZ($output));
    }

    /**
     * @return iterable<string, array{string, list<FileChange>}>
     */
    public static function unusualPaths(): iterable
    {
        yield 'non-ASCII' => ["A\0src/Jq/Über.php\0", [new FileChange('A', 'src/Jq/Über.php')]];

        yield 'space' => ["M\0src/Jq/A B.php\0", [new FileChange('M', 'src/Jq/A B.php')]];

        yield 'tab' => ["M\0src/Jq/A\tB.php\0", [new FileChange('M', "src/Jq/A\tB.php")]];

        yield 'quote and backslash' => ["M\0src/Jq/\"A\\B\".php\0", [new FileChange('M', 'src/Jq/"A\B".php')]];

        yield 'renamed to a non-ASCII name' => [
            "R100\0tests/Unit/Jq/OldTest.php\0tests/Unit/Jq/ÜberTest.php\0",
            [new FileChange('D', 'tests/Unit/Jq/OldTest.php'), new FileChange('R', 'tests/Unit/Jq/ÜberTest.php')],
        ];
    }

    public function testNoChangesIsAnEmptyList(): void
    {
        self::assertSame([], FileChange::parseNameStatusZ(''));
    }

    #[DataProvider('malformed')]
    public function testMalformedOutputIsRefusedRatherThanReadAsNoChange(string $output): void
    {
        self::assertNull(FileChange::parseNameStatusZ($output));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'line-based output instead of -z' => ["M\tsrc/Yaml/Node.php\n"];

        yield 'status without a path' => ["M\0"];

        yield 'rename with one path' => ["R100\0src/A.php\0"];

        yield 'unknown status' => ["Q\0src/A.php\0"];

        yield 'empty path' => ["M\0\0"];

        yield 'missing final terminator' => ["M\0src/A.php"];
    }
}
