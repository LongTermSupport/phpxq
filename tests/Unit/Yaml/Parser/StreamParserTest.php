<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Parser;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\Parser\StreamParser;
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
            self::assertSame(NodeKind::Document, $doc->kind);
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

    public function testNestedDepthDoesNotExhaustTheStack(): void
    {
        $yaml = str_repeat('[', 2000) . str_repeat(']', 2000);
        $docs = iterator_to_array(new StreamParser($yaml)->documents(), false);

        self::assertCount(1, $docs);
    }
}
