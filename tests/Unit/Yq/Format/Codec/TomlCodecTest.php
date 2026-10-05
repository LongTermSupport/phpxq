<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\Codec\TomlDecoder;
use LTS\PhpXq\Yq\Format\Codec\TomlEncoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TomlCodecTest extends TestCase
{
    #[DataProvider('decodeCases')]
    public function testDecodeShape(string $toml, string $expectedJson): void
    {
        $json = new JsonEncoder()->encode($this->decode($toml), new FormatOptions(indent: 0, unwrapScalar: false), 0);

        self::assertSame($expectedJson . "\n", $json);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function decodeCases(): iterable
    {
        yield 'scalars' => [
            "a = 1_000\nb = 0xFF\nc = 0b101\nd = 0o17\ne = +3.5e2\nh = true\ni = 1979-05-27 07:32:00Z\nj = 07:32:00\nk = 'lit\\x'\nl = \"esc\\t\\u00e9\\U0001F60A\"\n",
            '{"a":1000,"b":255,"c":5,"d":15,"e":3.5e2,"h":true,"i":"1979-05-27 07:32:00Z","j":"07:32:00","k":"lit\\\x","l":"esc\té😊"}',
        ];

        yield 'multi-line strings' => [
            "s = \"\"\"\nline1\nline2 \\\n   cont\"\"\"\nl = '''\nraw\\n'''\nq = \"\"\"a\"\"\"\"\n",
            '{"s":"line1\nline2 cont","l":"raw\\\n","q":"a\""}',
        ];

        yield 'dotted keys and tables' => [
            "a.b.c = 1\na.b.d = 2\n[x]\ny.z = 3\n[x.w]\nk = [1, [2, 3], {p = 1}]\n",
            '{"a":{"b":{"c":1,"d":2}},"x":{"y":{"z":3},"w":{"k":[1,[2,3],{"p":1}]}}}',
        ];

        yield 'arrays of tables' => [
            "[[a]]\nx = 1\n[a.sub]\nq = 1\n[[a]]\nx = 2\n[[a.deep]]\nz = 1\n",
            '{"a":[{"x":1,"sub":{"q":1}},{"x":2,"deep":[{"z":1}]}]}',
        ];

        yield 'arrays with comments and trailing commas' => ["a = [1,\n  2, # c\n  3,\n]\n", '{"a":[1,2,3]}'];

        yield 'quoted keys' => ["\"a b\" = 1\n'c.d' = 2\ne.\"f.g\" = 3\n", '{"a b":1,"c.d":2,"e":{"f.g":3}}'];

        yield 'crlf and bom' => ["\u{FEFF}a = 1\r\nb = 2\r\n", '{"a":1,"b":2}'];

        yield 'inline table with dotted key' => ["t = {a.b = 1, c = 2}\n", '{"t":{"a":{"b":1},"c":2}}'];

        yield 'implicit table can be defined later' => ["[a.b]\nx = 1\n[a]\ny = 2\n", '{"a":{"b":{"x":1},"y":2}}'];
    }

    #[DataProvider('invalidCases')]
    public function testInvalidInput(string $toml): void
    {
        $this->expectException(FormatException::class);
        $this->decode($toml);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCases(): iterable
    {
        yield 'duplicate key' => ["a = 1\na = 2\n"];

        yield 'duplicate table' => ["[a]\n[a]\n"];

        yield 'missing value' => ["a = \n"];

        yield 'bare word value' => ["a = nope\n"];

        yield 'missing equals' => ["a\n"];

        yield 'trailing garbage' => ["a = 1 2\n"];

        yield 'unterminated string' => ["a = \"x\n"];

        yield 'unterminated array' => ["a = [1, 2\n"];

        yield 'bad escape' => ["a = \"\\q\"\n"];

        yield 'bad unicode escape' => ["a = \"\\uD800\"\n"];

        yield 'unterminated table header' => ["[a\n"];

        yield 'extending an inline table' => ["a = {b = 1}\n[a.c]\n"];

        yield 'key is not a table' => ["a = 1\n[a.b]\n"];

        yield 'dotted into a scalar' => ["a = 1\na.b = 2\n"];

        yield 'array of tables over a table' => ["[a]\n[[a]]\n"];

        yield 'unterminated literal string' => ["a = 'x\n"];

        yield 'unterminated multi-line string' => ["a = \"\"\"x\n"];

        yield 'empty input of a key' => ["= 1\n"];
    }

    public function testEmptyInputHasNoDocuments(): void
    {
        self::assertSame([], [...new TomlDecoder()->decode("\n", new FormatOptions())]);
        self::assertSame(FormatEnum::Toml, new TomlDecoder()->format());
        self::assertSame(FormatEnum::Toml, new TomlEncoder()->format());
    }

    public function testSpecialFloatsUseYamlSpelling(): void
    {
        $root = $this->decode("a = inf\nb = -inf\nc = nan\nd = +inf\n")->root();

        self::assertSame(['.inf', '-.inf', '.nan', '.inf'], [$root->content[1]->value, $root->content[3]->value, $root->content[5]->value, $root->content[7]->value]);
        self::assertSame('!!float', $root->content[1]->tag);
    }

    public function testInlineTablesAreMarkedForTheEncoderOnly(): void
    {
        $inline = $this->decode("t = {a = 1}\n")->root()->content[1];

        self::assertTrue($inline->explicitStart);
        self::assertSame(\LTS\PhpXq\Yaml\NodeStyleEnum::Default, $inline->style);
    }

    public function testCommentsAreKept(): void
    {
        $document = $this->decode("# head\n[a]\n# inner\nx = 1 # tail\n\n[[b]]\ny = 2\n# end\n");

        self::assertSame('# head', $document->root()->content[0]->headComment);
        self::assertSame('# inner', $document->root()->content[1]->content[0]->headComment);
        self::assertSame('# tail', $document->root()->content[1]->content[1]->lineComment);
        self::assertSame('# end', $document->footComment);
    }

    #[DataProvider('encodeCases')]
    public function testEncode(string $yaml, string $expected): void
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            self::assertSame($expected, new TomlEncoder()->encode($document, new FormatOptions(), 0));

            return;
        }

        self::fail('no document');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function encodeCases(): iterable
    {
        yield 'scalars before tables' => [
            "e:\n  f: x\n  g:\n    h: y\na: 1\nb: [1, 2]\nc: {d: 1}\n",
            "a = 1\nb = [1, 2]\nc = { d = 1 }\n\n[e]\nf = \"x\"\n\n[e.g]\nh = \"y\"\n",
        ];

        yield 'array of tables' => ["i:\n  - j: 1\n  - j: 2\n", "[[i]]\nj = 1\n[[i]]\nj = 2\n"];

        yield 'mixed arrays are inline' => ["a:\n  - 1\n  - {x: 1}\n  - [1]\n", "a = [1, { x = 1 }, [1]]\n"];

        yield 'value kinds' => [
            "n: null\nt: true\nf: 1.5\ni: 0x10\ns: \"multi\\nline \\\"q\\\" \\u0001\"\nd: 2001-12-14T01:02:03Z\ninf: .inf\nninf: -.inf\nnan: .nan\n",
            "n = \"\"\nt = true\nf = 1.5\ni = 0x10\ns = \"multi\\nline \\\"q\\\" \\u0001\"\nd = 2001-12-14T01:02:03Z\ninf = inf\nninf = -inf\nnan = nan\n",
        ];

        yield 'keys that need quoting' => ["\"a b\": 1\n\"\": 2\nok-key_1: 3\n", "\"a b\" = 1\n\"\" = 2\nok-key_1 = 3\n"];

        yield 'empty table and comments' => ["# top\nt: {}\nu:\n  # about v\n  v: 1 # tail\n", "# top\nt = {}\n\n[u]\n# about v\nv = 1  # tail\n"];

        yield 'empty block table' => ["a:\n  b:\n    c: 1\n", "[a.b]\nc = 1\n"];

        yield 'top level scalar' => ["hello\n", "hello\n"];

        yield 'aliases' => ["x: &a {k: 1}\ny: *a\n", "x = { k = 1 }\ny = { k = 1 }\n"];
    }

    public function testSequenceRootIsRejected(): void
    {
        $this->expectException(FormatException::class);
        $encoder = new TomlEncoder();
        foreach (new YamlParser()->parse("- a\n") as $document) {
            $encoder->encode($document, new FormatOptions(), 0);
        }
    }

    public function testRoundTripOfADocument(): void
    {
        $toml = "# This is a TOML document\ntitle = \"TOML Example\"\n\n[owner]\nname = \"Tom\"\ndob = 1979-05-27T07:32:00-08:00\n\n[database]\nports = [8000, 8001]\ntemp = { cpu = 79.5, case = 72.0 }\n\n# servers\n[servers.alpha]\nip = \"10.0.0.1\"\n\n[[fruits]]\nname = \"apple\"\n[[fruits.varieties]]\nname = \"red\"\n";

        self::assertSame($toml, new TomlEncoder()->encode($this->decode($toml), new FormatOptions(), 0));
    }

    private function decode(string $toml): Node
    {
        foreach (new TomlDecoder()->decode($toml, new FormatOptions()) as $document) {
            return $document;
        }

        self::fail('no document');
    }
}
