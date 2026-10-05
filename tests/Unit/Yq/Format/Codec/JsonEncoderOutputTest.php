<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JsonEncoderOutputTest extends TestCase
{
    private const string INT = '!!int';

    private const string HALF_AND_ONE = '1.5';

    private const string FLOAT = '!!float';

    #[DataProvider('layoutCases')]
    public function testLayout(Node $node, FormatOptions $options, string $expected): void
    {
        self::assertSame($expected, new JsonEncoder()->encode($node, $options, 0));
    }

    /**
     * @return iterable<string, array{Node, FormatOptions, string}>
     */
    public static function layoutCases(): iterable
    {
        $pretty = new FormatOptions();
        $one    = Node::scalar('1');
        $two    = Node::scalar('2');
        $key    = static fn (string $name): Node => Node::scalar($name);

        yield 'nested mapping with two keys' => [
            Node::mapping([$key('a'), Node::mapping([$key('b'), $one, $key('c'), $two])]),
            $pretty,
            "{\n  \"a\": {\n    \"b\": 1,\n    \"c\": 2\n  }\n}\n",
        ];

        yield 'doubly nested mapping with two keys' => [
            Node::mapping([$key('a'), Node::mapping([$key('b'), Node::mapping([$key('c'), $one, $key('d'), $two])])]),
            $pretty,
            "{\n  \"a\": {\n    \"b\": {\n      \"c\": 1,\n      \"d\": 2\n    }\n  }\n}\n",
        ];

        yield 'nested sequences' => [
            Node::sequence([Node::sequence([$one, $two]), Node::sequence([Node::scalar('3'), Node::scalar('4')])]),
            $pretty,
            "[\n  [\n    1,\n    2\n  ],\n  [\n    3,\n    4\n  ]\n]\n",
        ];

        yield 'mappings in a sequence' => [
            Node::sequence([Node::mapping([$key('a'), $one, $key('b'), $two])]),
            $pretty,
            "[\n  {\n    \"a\": 1,\n    \"b\": 2\n  }\n]\n",
        ];

        yield 'sequences in a mapping in a sequence' => [
            Node::sequence([Node::mapping([$key('a'), Node::sequence([$one, $two])])]),
            new FormatOptions(indent: 3),
            "[\n   {\n      \"a\": [\n         1,\n         2\n      ]\n   }\n]\n",
        ];

        yield 'compact nested sequences' => [Node::sequence([Node::sequence([$one, $two]), $one]), new FormatOptions(indent: 0), "[[1,2],1]\n"];

        yield 'compact nested mappings' => [Node::mapping([$key('a'), Node::mapping([$key('b'), $one, $key('c'), $two])]), new FormatOptions(indent: 0), "{\"a\":{\"b\":1,\"c\":2}}\n"];

        yield 'negative indent is compact' => [Node::sequence([$one, $two]), new FormatOptions(indent: -1), "[1,2]\n"];

        yield 'alias inside a sequence' => [Node::sequence([Node::alias('a', $one), Node::alias('b', Node::document($two))]), new FormatOptions(indent: 0), "[1,2]\n"];

        yield 'empty collections' => [Node::sequence([Node::sequence(), Node::mapping()]), new FormatOptions(indent: 0), "[[],{}]\n"];

        yield 'non-scalar key is written as an empty string' => [Node::mapping([Node::sequence(), $one]), new FormatOptions(indent: 0), "{\"\":1}\n"];
    }

    #[DataProvider('colorCases')]
    public function testColors(Node $node, string $expected): void
    {
        self::assertSame($expected, new JsonEncoder()->encode($node, new FormatOptions(indent: 0, colors: true, unwrapScalar: false), 0));
    }

    /**
     * @return iterable<string, array{Node, string}>
     */
    public static function colorCases(): iterable
    {
        yield 'float' => [Node::scalar(self::HALF_AND_ONE), "\x1b[95m1.5\x1b[0m\n"];

        yield 'integer' => [Node::scalar('1'), "\x1b[95m1\x1b[0m\n"];

        yield 'boolean' => [Node::scalar('true'), "\x1b[95mtrue\x1b[0m\n"];

        yield 'string' => [Node::scalar('x', '!!str'), "\x1b[32m\"x\"\x1b[0m\n"];

        yield 'null' => [Node::scalar('~'), "null\n"];

        yield 'key and values' => [Node::mapping([Node::scalar('a'), Node::scalar('2.5')]), "{\x1b[36m\"a\"\x1b[0m:\x1b[95m2.5\x1b[0m}\n"];
    }

    #[DataProvider('numberCases')]
    public function testNumbers(string $value, string $tag, string $expected): void
    {
        $out = new JsonEncoder()->encode(Node::scalar($value, $tag), new FormatOptions(unwrapScalar: false), 0);

        self::assertSame($expected . "\n", $out);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function numberCases(): iterable
    {
        yield 'zero' => ['0', self::INT, '0'];

        yield 'plain integer' => ['42', self::INT, '42'];

        yield 'integer with leading zeros' => ['007', self::INT, '7'];

        yield 'hex integer' => ['0x1F', self::INT, '31'];

        yield 'octal integer' => ['0o17', self::INT, '15'];

        yield 'negative integer' => ['-5', self::INT, '-5'];

        yield 'integer with a plus' => ['+5', self::INT, '5'];

        yield 'integer that is not a number' => ['1x', self::INT, '"1x"'];

        yield 'empty integer text' => ['', self::INT, '""'];

        yield 'integer that starts like a number' => ['0z', self::INT, '"0z"'];

        yield 'float' => [self::HALF_AND_ONE, self::FLOAT, self::HALF_AND_ONE];

        yield 'float with an exponent' => ['1e5', self::FLOAT, '1e5'];

        yield 'float with a signed exponent' => ['-2.5E-3', self::FLOAT, '-2.5E-3'];

        yield 'float with a trailing dot' => ['1.', self::FLOAT, '1.0'];

        yield 'float with a leading dot' => ['.5', self::FLOAT, '0.5'];

        yield 'negative float with a leading dot' => ['-.5', self::FLOAT, '-0.5'];

        yield 'float with a plus sign' => ['+1.5', self::FLOAT, self::HALF_AND_ONE];

        yield 'float with a trailing dot and an exponent' => ['5.e3', self::FLOAT, '5.0e3'];

        yield 'float with a leading dot and an exponent' => ['.5e3', self::FLOAT, '0.5e3'];

        yield 'integer-looking float' => ['5', self::FLOAT, '5'];

        yield 'float with only an exponent mark' => ['e5', self::FLOAT, '0e5'];

        yield 'float that is not a number' => ['abc', self::FLOAT, '"abc"'];

        yield 'float with a suffix' => ['1.5x', self::FLOAT, '"1.5x"'];

        yield 'float with a prefix' => ['x1.5', self::FLOAT, '"x1.5"'];
    }

    #[DataProvider('unsupportedFloatCases')]
    public function testInfinityAndNanAreRejected(string $value): void
    {
        try {
            new JsonEncoder()->encode(Node::scalar($value, self::FLOAT), new FormatOptions(unwrapScalar: false), 0);
        } catch (FormatException $exception) {
            self::assertSame('json: unsupported value: ' . $value, $exception->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedFloatCases(): iterable
    {
        yield 'infinity' => ['.inf'];

        yield 'negative infinity' => ['-.inf'];

        yield 'positive infinity' => ['+.Inf'];

        yield 'upper case infinity' => ['.INF'];

        yield 'not a number' => ['.nan'];

        yield 'mixed case not a number' => ['.NaN'];

        yield 'upper case not a number' => ['.NAN'];
    }

    #[DataProvider('lookalikeCases')]
    public function testInfinityLookalikesAreStrings(string $value, string $expected): void
    {
        $out = new JsonEncoder()->encode(Node::scalar($value, self::FLOAT), new FormatOptions(unwrapScalar: false), 0);

        self::assertSame($expected . "\n", $out);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lookalikeCases(): iterable
    {
        yield 'infinity with a suffix' => ['.infx', '".infx"'];

        yield 'infinity with a prefix' => ['x.inf', '"x.inf"'];

        yield 'signed not a number' => ['-.nan', '"-.nan"'];

        yield 'not a number with a suffix' => ['.nanx', '".nanx"'];
    }
}
