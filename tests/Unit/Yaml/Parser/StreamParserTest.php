<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Parser;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Parser\StreamParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class StreamParserTest extends TestCase
{
    public function testDocumentsGeneratorYieldsDocumentNodes(): void
    {
        $docs = iterator_to_array(new StreamParser("a: 1\n---\nb\n")->documents(), false);

        self::assertCount(2, $docs);
        foreach ($docs as $doc) {
            self::assertSame(NodeKindEnum::Document, $doc->kind);
            self::assertCount(1, $doc->content);
        }
    }

    public function testInvalidInputIsRejectedByTheConstructor(): void
    {
        $this->expectException(YamlSyntaxException::class);
        new StreamParser("a: \x00");
    }

    public function testEmptyKeyAndValuePositions(): void
    {
        $doc  = iterator_to_array(new StreamParser("a:\n")->documents(), false)[0];
        $null = $doc->content[0]->content[1];

        self::assertSame('!!null', $null->tag);
        self::assertSame([1, 3], [$null->line, $null->column]);
    }

    public function testDuplicateTagHandleIsRejectedAtTheSecondDirective(): void
    {
        try {
            $this->firstRoot("%TAG !e! a\n%TAG !e! b\n---\nx\n");
        } catch (YamlSyntaxException $yamlSyntaxException) {
            self::assertSame('yaml: line 2: found duplicate %TAG directive', $yamlSyntaxException->getMessage());
            self::assertSame([2, 1], [$yamlSyntaxException->yamlLine, $yamlSyntaxException->yamlColumn]);

            return;
        }

        self::fail('expected a syntax error');
    }

    public function testErrorsReportOneBasedLineAndColumnOfTheOffendingToken(): void
    {
        try {
            $this->firstRoot("[a,\n  : x]");
        } catch (YamlSyntaxException $yamlSyntaxException) {
            self::assertSame('yaml: line 2: did not find expected node content', $yamlSyntaxException->getMessage());
            self::assertSame([2, 3], [$yamlSyntaxException->yamlLine, $yamlSyntaxException->yamlColumn]);

            return;
        }

        self::fail('expected a syntax error');
    }

    #[DataProvider('dumpProvider')]
    public function testNodeTrees(string $yaml, string $expected): void
    {
        self::assertSame($expected, NodeDump::dump($this->firstRoot($yaml)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dumpProvider(): iterable
    {
        yield 'anchor and tag without content' => ["- &a !!str\n- b", '[&a !tag:!!str !!str=; !!str=b]'];

        yield 'tag without content' => ["- !!str\n- b", '[!tag:!!str !!str=; !!str=b]'];

        yield 'anchor without content' => ["- &a\n- b", '[&a !!null=; !!str=b]'];

        yield 'flow pair without key before value' => ['[? : x]', 'flow[flow{!!null=: !!str=x}]'];

        yield 'flow pair without key or value before entry' => ['[? , a]', 'flow[flow{!!null=: !!null=}; !!str=a]'];

        yield 'flow pair without key or value before end' => ['[? ]', 'flow[flow{!!null=: !!null=}]'];

        yield 'flow pair foot comment after a line comment on an explicit key' => ["[? a # k\n# f1\n: b # v\n# f2\n]", 'flow[flow{!!str=a#l(# k)#f(# f2): !!str=b#h(# f1)#l(# v)}]'];

        yield 'flow pair without value before entry' => ['[a: , b]', 'flow[flow{!!str=a: !!null=}; !!str=b]'];

        yield 'flow pair without value before end' => ['[a: ]', 'flow[flow{!!str=a: !!null=}]'];

        yield 'flow pair foot comment moves to the key' => ["[a: b\n# foot\n]", 'flow[flow{!!str=a#f(# foot): !!str=b}]'];

        yield 'flow pair foot comment moves to the key after a line comment' => ["[a: b # l\n# foot\n]", 'flow[flow{!!str=a#f(# foot): !!str=b#l(# l)}]'];

        yield 'flow mapping foot comment' => ["{a: b\n# foot\n}", 'flow{!!str=a#f(# foot): !!str=b}'];
    }

    #[DataProvider('pairPositionProvider')]
    public function testFlowSequencePairsStartAtTheirKey(string $yaml, int $line, int $column): void
    {
        $items = $this->firstRoot($yaml)->content;
        $pair  = $items[array_key_last($items)];

        self::assertSame([$line, $column], [$pair->line, $pair->column]);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function pairPositionProvider(): iterable
    {
        yield 'first line' => ['[a: 1]', 1, 2];

        yield 'indented on the second line' => ["[\n  a: 1]", 2, 3];

        yield 'after a plain item' => ['[x, y: 1]', 1, 5];
    }

    public function testNestedDepthDoesNotExhaustTheStack(): void
    {
        $yaml = str_repeat('[', 2000) . str_repeat(']', 2000);
        $docs = iterator_to_array(new StreamParser($yaml)->documents(), false);

        self::assertCount(1, $docs);
    }

    private function firstRoot(string $yaml): Node
    {
        $doc = iterator_to_array(new StreamParser($yaml)->documents(), false)[0];

        return $doc->content[0];
    }
}
