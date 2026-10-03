<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JsonEncoderTest extends TestCase
{
    public function testFormat(): void
    {
        self::assertSame(FormatEnum::Json, new JsonEncoder()->format());
    }

    public function testPrettyPrintsWithTwoSpaces(): void
    {
        $out = $this->encode("cat: meow\nlist: [1, 2]\nempty: {}\nnone: []\nnested:\n  a: b\n");

        self::assertSame("{\n  \"cat\": \"meow\",\n  \"list\": [\n    1,\n    2\n  ],\n  \"empty\": {},\n  \"none\": [],\n  \"nested\": {\n    \"a\": \"b\"\n  }\n}\n", $out);
    }

    public function testIndentZeroIsCompact(): void
    {
        self::assertSame("{\"percentiles\":[50.0,95.0,99.9]}\n", $this->encode('percentiles: [50.0, 95.0, 99.9]', new FormatOptions(indent: 0)));
    }

    public function testCustomIndent(): void
    {
        self::assertSame("[\n    1\n]\n", $this->encode('[1]', new FormatOptions(indent: 4)));
    }

    public function testResolvesAliasesAndMerges(): void
    {
        $out = $this->encode("cat: &ref meow\nanotherCat: *ref\nbase: &b {x: 1, y: 2}\nm:\n  <<: *b\n  y: 9\n", new FormatOptions(indent: 0));

        self::assertSame("{\"cat\":\"meow\",\"anotherCat\":\"meow\",\"base\":{\"x\":1,\"y\":2},\"m\":{\"x\":1,\"y\":9}}\n", $out);
    }

    #[DataProvider('scalarCases')]
    public function testScalarRendering(string $yaml, string $expected): void
    {
        self::assertSame($expected . "\n", $this->encode($yaml, new FormatOptions(indent: 0, unwrapScalar: false)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function scalarCases(): iterable
    {
        yield 'string' => ['hello', '"hello"'];

        yield 'quoted number stays string' => ['"12"', '"12"'];

        yield 'int' => ['12', '12'];

        yield 'signed int' => ['+12', '12'];

        yield 'hex' => ['0x1F', '31'];

        yield 'octal' => ['0o17', '15'];

        yield 'float' => ['3.40', '3.40'];

        yield 'leading dot float' => ['.5', '0.5'];

        yield 'trailing dot float' => ['5.', '5.0'];

        yield 'exponent' => ['1e3', '1e3'];

        yield 'bool' => ['True', 'true'];

        yield 'null' => ['~', 'null'];

        yield 'timestamp is a string' => ['2001-12-14', '"2001-12-14"'];

        yield 'escapes' => ['"a\"b\\\c\n\t\u0001é"', '"a\"b\\\c\n\t\u0001é"'];

        yield 'html is not escaped' => ['"<a&b>"', '"<a&b>"'];

        yield 'line separator' => ["\"\u{2028}\"", '"\u2028"'];

        yield 'backspace and form feed' => ['"\b\f"', '"\u0008\u000c"'];
    }

    public function testColorsPaintKeysStringsAndNumbers(): void
    {
        $out = self::encode("a: x\nb: [1, true, null]\n", new FormatOptions(indent: 0, colors: true));

        self::assertSame("{\x1b[36m\"a\"\x1b[0m:\x1b[32m\"x\"\x1b[0m,\x1b[36m\"b\"\x1b[0m:[\x1b[95m1\x1b[0m,\x1b[95mtrue\x1b[0m,null]}\n", $out);
    }

    public function testTopLevelStringIsBareWhenUnwrapping(): void
    {
        self::assertSame("cat\n", $this->encode('cat', new FormatOptions(unwrapScalar: true)));
    }

    public function testTopLevelStringIsQuotedWhenNotUnwrapping(): void
    {
        self::assertSame("\"cat\"\n", $this->encode('cat', new FormatOptions(unwrapScalar: false)));
    }

    public function testInfinityIsRejected(): void
    {
        $this->expectException(FormatException::class);
        $this->encode('.inf', new FormatOptions(unwrapScalar: false));
    }

    public function testInvalidUtf8BecomesReplacementCharacter(): void
    {
        $node = Node::scalar("a\xFFb", '!!str');

        self::assertSame("\"a\u{FFFD}b\"\n", new JsonEncoder()->encode($node, new FormatOptions(unwrapScalar: false), 0));
    }

    public function testNonScalarKeyBecomesEmptyString(): void
    {
        $node = Node::mapping([Node::sequence(), Node::scalar('x')]);

        self::assertSame("{\"\":\"x\"}\n", new JsonEncoder()->encode($node, new FormatOptions(indent: 0), 0));
    }

    public function testAliasCycleIsRejected(): void
    {
        $seq            = Node::sequence();
        $seq->content[] = Node::alias('a', $seq);

        $this->expectException(FormatException::class);
        new JsonEncoder()->encode($seq, new FormatOptions(), 0);
    }

    private function encode(string $yaml, ?FormatOptions $options = null): string
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            self::assertSame(NodeKindEnum::Document, $document->kind);

            return new JsonEncoder()->encode($document, $options ?? new FormatOptions(), 0);
        }

        self::fail('no document');
    }
}
