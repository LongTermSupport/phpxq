<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yq\Format\Codec\JsonDecoder;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JsonDecoderTest extends TestCase
{
    public function testFormat(): void
    {
        self::assertSame(Format::Json, new JsonDecoder()->format());
    }

    public function testObjectKeepsOrderAndTagsScalars(): void
    {
        $docs = $this->decode('{"b": 1, "a": [true, null, 1.50, "x"], "c": {"d": -2e3}}');

        self::assertCount(1, $docs);
        self::assertSame(NodeKind::Document, $docs[0]->kind);
        $root = $docs[0]->root();
        self::assertSame(NodeKind::Mapping, $root->kind);
        self::assertSame(['b', 'a', 'c'], [$root->content[0]->value, $root->content[2]->value, $root->content[4]->value]);
        self::assertSame(['!!int', '1'], [$root->content[1]->tag, $root->content[1]->value]);

        $list = $root->content[3]->content;
        self::assertSame(['!!bool', 'true'], [$list[0]->tag, $list[0]->value]);
        self::assertSame(['!!null', 'null'], [$list[1]->tag, $list[1]->value]);
        self::assertSame(['!!float', '1.5'], [$list[2]->tag, $list[2]->value]);
        self::assertSame(['!!str', 'x'], [$list[3]->tag, $list[3]->value]);

        $inner = $root->content[5];
        self::assertSame(['!!int', '-2000'], [$inner->content[1]->tag, $inner->content[1]->value]);
    }

    public function testStringEscapes(): void
    {
        $docs = $this->decode('"a\"b\\\c\/d\n\té😊\ud800x"');

        self::assertSame("a\"b\\c/d\n\té😊\u{FFFD}x", $docs[0]->root()->value);
    }

    public function testMultipleDocuments(): void
    {
        $docs = $this->decode("{\"a\": 1}\n{\"b\": 2}  [3]\n\n");

        self::assertCount(3, $docs);
        self::assertSame(NodeKind::Sequence, $docs[2]->root()->kind);
    }

    public function testEmptyInputHasNoDocuments(): void
    {
        self::assertSame([], $this->decode(" \n"));
    }

    public function testBomIsSkipped(): void
    {
        self::assertSame('1', $this->decode("\u{FEFF}1")[0]->root()->value);
    }

    public function testDuplicateKeysAreKept(): void
    {
        $root = $this->decode('{"a": 1, "b": 2, "a": 3}')[0]->root();

        self::assertCount(6, $root->content);
        self::assertSame('3', $root->content[5]->value);
    }

    public function testNumbersFollowGoFloatFormatting(): void
    {
        $root = $this->decode('[1.0, 1e5, 1e6, 0.00001, 12345678901234567890, -0, 123456789012345678]')[0]->root();

        self::assertSame(
            ['1', '100000', '1e+06', '1e-05', '1.2345678901234567e+19', '0', '123456789012345678'],
            array_map(static fn (Node $n): string => $n->value, $root->content),
        );
    }

    #[DataProvider('invalidCases')]
    public function testInvalidInput(string $json): void
    {
        $this->expectException(FormatException::class);
        $this->decode($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCases(): iterable
    {
        yield 'unterminated object' => ['{"a": 1'];

        yield 'trailing comma' => ['[1,]'];

        yield 'bare word' => ['nope'];

        yield 'bad number' => ['01'];

        yield 'unterminated string' => ['"abc'];

        yield 'control character' => ["\"a\nb\""];

        yield 'bad escape' => ['"\q"'];

        yield 'missing colon' => ['{"a" 1}'];

        yield 'non string key' => ['{1: 2}'];
    }

    /**
     * @return list<Node>
     */
    private function decode(string $json): array
    {
        return [...new JsonDecoder()->decode($json, new FormatOptions())];
    }
}
