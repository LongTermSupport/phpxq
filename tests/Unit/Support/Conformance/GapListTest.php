<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Conformance;

use InvalidArgumentException;
use LTS\PhpXq\Tests\Support\Conformance\GapList;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class GapListTest extends TestCase
{
    public function testIgnoresBlankAndCommentLines(): void
    {
        $gaps = GapList::fromLines('# header', '', "jq.test:1\tbecause", '#another');

        self::assertCount(1, $gaps->entries());
        self::assertSame('jq.test:1', $gaps->entries()[0]->glob);
        self::assertSame('because', $gaps->entries()[0]->reason);
    }

    public function testGlobSemanticsAndFirstMatchWins(): void
    {
        $gaps = GapList::fromLines("jq.test:1?\tfirst", "jq.test:*\tsecond", "[ab]x\tthird");

        self::assertSame('first', $gaps->reasonFor('jq.test:12'));
        self::assertSame('second', $gaps->reasonFor('jq.test:9'));
        self::assertSame('third', $gaps->reasonFor('bx'));
        self::assertNull($gaps->reasonFor('cx'));
    }

    public function testBlanketEntryMatchesEverything(): void
    {
        self::assertSame('all', GapList::fromLines("*\tall")->reasonFor('operators/add.md: Anything / x'));
    }

    public function testReasonMayContainTabs(): void
    {
        self::assertSame("a\tb", GapList::fromLines("x\ta\tb")->reasonFor('x'));
    }

    public function testMissingTabThrowsWithLineNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/line 3/');

        GapList::fromLines('# c', '', 'no tab here');
    }

    public function testEmptyReasonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/line 1/');

        GapList::fromLines("glob\t   ");
    }

    public function testEmptyGlobThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GapList::fromLines("\treason");
    }

    public function testFromFileHandlesCrlfAndTrailingNewline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gaps');
        self::assertNotFalse($path);
        file_put_contents($path, "# c\r\nfoo\tbar\r\n\r\n");

        try {
            $gaps = GapList::fromFile($path);
        } finally {
            unlink($path);
        }

        self::assertSame('bar', $gaps->reasonFor('foo'));
        self::assertCount(1, $gaps->entries());
    }

    public function testFromFileMissingThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GapList::fromFile('/nonexistent/gaps.txt');
    }
}
