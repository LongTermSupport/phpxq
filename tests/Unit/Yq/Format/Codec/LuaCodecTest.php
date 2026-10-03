<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\Codec\LuaDecoder;
use LTS\PhpXq\Yq\Format\Codec\LuaEncoder;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LuaCodecTest extends TestCase
{
    #[DataProvider('encodeCases')]
    public function testEncode(string $yaml, string $expected, ?FormatOptions $options = null): void
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            self::assertSame($expected, new LuaEncoder()->encode($document, $options ?? new FormatOptions(), 0));

            return;
        }

        self::fail('no document');
    }

    /**
     * @return iterable<string, array{string, string, 2?: FormatOptions}>
     */
    public static function encodeCases(): iterable
    {
        yield 'map with every scalar kind' => [
            "a: 1\nb: [x, true, null, 1.5, .nan, .inf, -.inf, 0x1F, 0o30]\n'k-1': v\nend: 1\n",
            "return {\n\t[\"a\"] = 1;\n\t[\"b\"] = {\n\t\t\"x\",\n\t\ttrue,\n\t\tnil,\n\t\t1.5,\n\t\t(0/0),\n\t\t(1/0),\n\t\t(-1/0),\n\t\t0x1F,\n\t\t24,\n\t};\n\t[\"k-1\"] = \"v\";\n\t[\"end\"] = 1;\n};\n",
        ];

        yield 'unquoted keys only where legal' => [
            "a: 1\nb: [x]\n'k-1': v\nend: 1\n",
            "return {\n\ta = 1;\n\tb = {\n\t\t\"x\",\n\t};\n\t[\"k-1\"] = \"v\";\n\t[\"end\"] = 1;\n};\n",
            new FormatOptions(luaUnquoted: true),
        ];

        yield 'globals' => ["a: 1\nb: [x]\n", "a = 1;\nb = {\n\t\"x\",\n};\n", new FormatOptions(luaGlobals: true)];

        yield 'string escapes' => ["a: \"q\\\" \\\\ \\n \\t \\u0001\"\n", "return {\n\t[\"a\"] = \"q\\\" \\\\ \\n \\t \\001\";\n};\n"];

        yield 'comments' => [
            "# head\na: 1 # tail\nb:\n  # inner\n  - x # item\n",
            "return {\n\t-- head\n\t[\"a\"] = 1; -- tail\n\t[\"b\"] = {\n\t\t-- inner\n\t\t\"x\", -- item\n\t};\n};\n",
        ];

        yield 'non-string keys' => ["1: a\n2.5: b\n? [x]\n: c\n", "return {\n\t[1] = \"a\";\n\t[2.5] = \"b\";\n\t[{\n\t\t\"x\",\n\t}] = \"c\";\n};\n"];

        yield 'empty collections' => ["a: []\nb: {}\n", "return {\n\t[\"a\"] = {};\n\t[\"b\"] = {};\n};\n"];

        yield 'bare scalar' => ["hello\n", "return \"hello\";\n"];

        yield 'array root' => ["- a\n- b\n", "return {\n\t\"a\",\n\t\"b\",\n};\n"];

        yield 'timestamps and unknown tags are strings' => ["a: !!timestamp 2001-12-14\n", "return {\n\t[\"a\"] = \"2001-12-14\";\n};\n"];
    }

    #[DataProvider('decodeCases')]
    public function testDecode(string $lua, string $expectedJson): void
    {
        $json = new JsonEncoder()->encode($this->decode($lua), new FormatOptions(indent: 0, unwrapScalar: false), 0);

        self::assertSame($expectedJson . "\n", $json);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function decodeCases(): iterable
    {
        yield 'documented example' => [
            "return {\n\t[\"country\"] = \"Australia\"; -- this place\n\t[\"cities\"] = {\n\t\t\"Sydney\",\n\t\t\"Perth\",\n\t};\n};\n",
            '{"country":"Australia","cities":["Sydney","Perth"]}',
        ];

        yield 'mixed table' => [
            "return { 1, 2, [3] = 'x', y = {z = 1.5e3, w = -2, v = 0xFF}, [\"q\"] = [[long\nstring]], t = true, n = nil }",
            '{"1":1,"2":2,"3":"x","y":{"z":1.5e3,"w":-2,"v":255},"q":"long\nstring","t":true,"n":null}',
        ];

        yield 'globals and comments' => ["a = 1; b = 'x'\n-- comment\n--[[ block\n]] c = {1, 2}\n", '{"a":1,"b":"x","c":[1,2]}'];

        yield 'string escapes' => ["return \"a\\n\\t\\\\\\\"\\65\\x41\\u{263A}\\z\n   b\" ", '"a\n\t\\\\\"AA☺b"'];

        yield 'long strings with levels' => ['return [==[a]]b]==]', '"a]]b"'];
    }

    public function testSpecialNumbersUseYamlSpelling(): void
    {
        $content = $this->decode('return {(1/0), (-1/0), (0/0), 6/3, - 5, -(1/0)}')->root()->content;

        self::assertSame(['.inf', '-.inf', '.nan', '2', '-5', '-.inf'], array_map(static fn (Node $node): string => $node->value, $content));
        self::assertSame('!!float', $content[0]->tag);
    }

    public function testNumberKinds(): void
    {
        $content = $this->decode('return {1, 1.5, .5, 1e3, 0xff, 7.}')->root()->content;

        self::assertSame(['!!int', '!!float', '!!float', '!!float', '!!int', '!!float'], array_map(static fn (Node $node): string => $node->tag, $content));
    }

    #[DataProvider('invalidCases')]
    public function testInvalidInput(string $lua): void
    {
        $this->expectException(FormatException::class);
        $this->decode($lua);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCases(): iterable
    {
        yield 'unterminated table' => ['return {1, 2'];

        yield 'trailing text' => ['return 5 foo'];

        yield 'missing comma' => ['return {1 2}'];

        yield 'unknown expression' => ['return foo'];

        yield 'unterminated string' => ['return "abc'];

        yield 'bad escape' => ['return "\q"'];

        yield 'bad hex escape' => ['return "\xZZ"'];

        yield 'bad unicode escape' => ['return "\u12"'];

        yield 'unterminated long string' => ['return [[abc'];

        yield 'missing assignment' => ['a'];

        yield 'bad division' => ['return {"a"/1}'];

        yield 'negating a string' => ['return -"a"'];

        yield 'unclosed paren' => ['return (1'];

        yield 'unexpected end' => ['return '];

        yield 'missing bracket' => ['return {[1 = 2}'];

        yield 'malformed number' => ['return 0x'];

        yield 'excessive nesting' => ['return ' . str_repeat('{', 600)];
    }

    public function testEmptyInputHasNoDocuments(): void
    {
        self::assertSame([], [...new LuaDecoder()->decode(" \n", new FormatOptions())]);
        self::assertSame(Format::Lua, new LuaDecoder()->format());
        self::assertSame(Format::Lua, new LuaEncoder()->format());
    }

    private function decode(string $lua): Node
    {
        foreach (new LuaDecoder()->decode($lua, new FormatOptions()) as $document) {
            return $document;
        }

        self::fail('no document');
    }
}
