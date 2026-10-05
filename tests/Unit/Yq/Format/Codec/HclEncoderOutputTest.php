<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\HclDecoder;
use LTS\PhpXq\Yq\Format\Codec\HclEncoder;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class HclEncoderOutputTest extends TestCase
{
    #[DataProvider('cases')]
    public function testEncode(string $yaml, string $expected): void
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            self::assertSame($expected, new HclEncoder()->encode($document, new FormatOptions(), 0));

            return;
        }

        self::fail('no document');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'empty list is an attribute' => ["a: []\n", "a = []\n"];

        yield 'list of scalars is an attribute' => ["a: [1, 2]\n", "a = [1, 2]\n"];

        yield 'list of maps is a block per item' => ["a:\n  - x: 1\n  - x: 2\n", "a {\n  x = 1\n}\na {\n  x = 2\n}\n"];

        yield 'object inside a list is inline' => ["a: [1, {x: 1}]\n", "a = [1, { x = 1 }]\n"];

        yield 'object inside an object inside a list is inline' => ["a: [1, {x: {y: 2}}]\n", "a = [1, { x = { y = 2 } }]\n"];

        yield 'upper case booleans' => ["a: TRUE\nb: False\nc: true\n", "a = true\nb = false\nc = true\n"];

        yield 'hex and octal integers are written in decimal' => ["a: 0x1F\nb: 0o17\nc: -7\n", "a = 31\nb = 15\nc = -7\n"];

        yield 'floats are kept as written' => ["a: 1.50\nb: 1e3\n", "a = 1.50\nb = 1e3\n"];

        yield 'backslashes quotes and control characters are escaped' => ["a: \"x\\\\y\\\"z\\ntab\\there\\rend\"\n", "a = \"x\\\\y\\\"z\\ntab\\there\\rend\"\n"];

        yield 'a nested key with a backslash is quoted and escaped' => ["a:\n  \"b\\\\c\": {x: 1}\n", "a {\n  \"b\\\\c\" {\n    x = 1\n  }\n}\n"];

        yield 'keys that are not identifiers are quoted' => ["\"a b\": 1\n\"1a\": 2\nok_1-x: 3\n", "\"a b\" = 1\n\"1a\" = 2\nok_1-x = 3\n"];

        yield 'null and an empty object block' => ["a: ~\nb: {}\n", "a = null\nb {\n}\n"];

        yield 'string that looks like a number stays quoted' => ["a: \"12\"\n", "a = \"12\"\n"];

        yield 'nested object attribute is multi-line' => ["a:\n  b:\n    c: 1\n", "a {\n  b {\n    c = 1\n  }\n}\n"];
    }

    #[DataProvider('roundTripCases')]
    public function testLabelledBlocksRoundTrip(string $hcl): void
    {
        foreach (new HclDecoder()->decode($hcl, new FormatOptions()) as $document) {
            self::assertSame($hcl, new HclEncoder()->encode($document, new FormatOptions(), 0));

            return;
        }

        self::fail('no document');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roundTripCases(): iterable
    {
        yield 'one label' => ["resource \"a\" {\n  x = 1\n}\n"];

        yield 'two labels' => ["resource \"a\" \"b\" {\n  x = 1\n}\n"];

        yield 'three labels' => ["resource \"a\" \"b\" \"c\" {\n  x = 1\n}\n"];

        yield 'sibling blocks sharing the first labels' => ["resource \"a\" \"b\" {\n  x = 1\n}\nresource \"a\" \"c\" {\n  y = 2\n}\n"];

        yield 'unlabelled blocks' => ["ingress {\n  port = 1\n}\ningress {\n  port = 2\n}\n"];
    }
}
