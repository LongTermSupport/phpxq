<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Jq;

use InvalidArgumentException;
use LTS\PhpXq\Tests\Support\Jq\JqTestFileParser;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class JqTestFileParserTest extends TestCase
{
    public function testParsesMultiOutputCase(): void
    {
        $cases = new JqTestFileParser()->parseString(".[]\n[1,2]\n1\n2\n", 'x.test');

        self::assertCount(1, $cases);
        self::assertSame('.[]', $cases[0]->program);
        self::assertSame('[1,2]', $cases[0]->input);
        self::assertSame(['1', '2'], $cases[0]->expectedOutputs);
        self::assertFalse($cases[0]->shouldFail);
        self::assertSame('x.test', $cases[0]->sourceFile);
        self::assertSame(1, $cases[0]->sourceLine);
        self::assertSame('x.test:1', $cases[0]->name());
    }

    public function testParsesNoOutputCase(): void
    {
        $cases = new JqTestFileParser()->parseString("empty\nnull\n\n.\nnull\nnull\n", 'x.test');

        self::assertCount(2, $cases);
        self::assertSame([], $cases[0]->expectedOutputs);
        self::assertSame(['null'], $cases[1]->expectedOutputs);
        self::assertSame(4, $cases[1]->sourceLine);
    }

    public function testSkipsCommentsAndBlankLinesBetweenCases(): void
    {
        $contents = "# header\n\n   # indented comment\n.a\n{\"a\":1}\n1\n# ends the block\n.b\n{\"b\":2}\n2\n";

        $cases = new JqTestFileParser()->parseString($contents, 'x.test');

        self::assertCount(2, $cases);
        self::assertSame(['1'], $cases[0]->expectedOutputs);
        self::assertSame(4, $cases[0]->sourceLine);
        self::assertSame('.b', $cases[1]->program);
        self::assertSame(8, $cases[1]->sourceLine);
    }

    public function testBlankLineSeparatesCasesWithMultipleBlankLines(): void
    {
        $cases = new JqTestFileParser()->parseString(".\n1\n1\n\n\n\n.\n2\n2\n", 'x.test');

        self::assertCount(2, $cases);
        self::assertSame(7, $cases[1]->sourceLine);
    }

    public function testParsesFailCaseWithMessageLines(): void
    {
        $contents = "%%FAIL\n.[\njq: error: syntax error at <top-level>, line 1:\n    .[\njq: 1 compile error\n\n.\nnull\nnull\n";

        $cases = new JqTestFileParser()->parseString($contents, 'x.test');

        self::assertCount(2, $cases);
        self::assertTrue($cases[0]->shouldFail);
        self::assertFalse($cases[0]->failIgnoreMessage);
        self::assertSame('.[', $cases[0]->program);
        self::assertSame(2, $cases[0]->sourceLine);
        self::assertSame('', $cases[0]->input);
        self::assertSame([], $cases[0]->expectedOutputs);
        self::assertSame(
            ['jq: error: syntax error at <top-level>, line 1:', '    .[', 'jq: 1 compile error'],
            $cases[0]->expectedMessageLines,
        );
        self::assertFalse($cases[1]->shouldFail);
    }

    public function testParsesFailIgnoreMessageCase(): void
    {
        $cases = new JqTestFileParser()->parseString("%%FAIL IGNORE MSG\nimport \"x\" as e; .\njq: error: whatever\n\n.\n1\n1\n", 'x.test');

        self::assertCount(2, $cases);
        self::assertTrue($cases[0]->shouldFail);
        self::assertTrue($cases[0]->failIgnoreMessage);
        self::assertSame('import "x" as e; .', $cases[0]->program);
        self::assertSame(['jq: error: whatever'], $cases[0]->expectedMessageLines);
    }

    public function testParsesTrailingCaseWithoutFinalBlankLineOrNewline(): void
    {
        $cases = new JqTestFileParser()->parseString(".\n1\n1", 'x.test');

        self::assertCount(1, $cases);
        self::assertSame(['1'], $cases[0]->expectedOutputs);
    }

    public function testParsesFailCaseAtEndOfFile(): void
    {
        $cases = new JqTestFileParser()->parseString("%%FAIL\n.[\njq: error: bad", 'x.test');

        self::assertCount(1, $cases);
        self::assertSame(['jq: error: bad'], $cases[0]->expectedMessageLines);
    }

    public function testKeepsLeadingByteOrderMarkInInput(): void
    {
        $cases = new JqTestFileParser()->parseString(".\n\u{FEFF}\"x\"\n\"x\"\n", 'x.test');

        self::assertSame("\u{FEFF}\"x\"", $cases[0]->input);
    }

    public function testRejectsProgramWithoutInputLine(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JqTestFileParser()->parseString('.', 'x.test');
    }
}
