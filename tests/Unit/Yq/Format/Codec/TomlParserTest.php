<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\Codec\TomlParser;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TomlParserTest extends TestCase
{
    private const string TABLE_A_DEFINED = 'toml: line 2: table a is already defined';

    private const string EXPECTED_VALUE = 'toml: line 1: expected a value';

    private const string TRAILING = 'toml: line 1: unexpected characters after value';

    private const string BAD_SCALAR = 'toml: line 1: invalid unicode scalar value';

    #[DataProvider('errorCases')]
    public function testErrorMessages(string $toml, string $expectedMessage): void
    {
        try {
            new TomlParser($toml)->parse();
        } catch (FormatException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorCases(): iterable
    {
        yield 'table header without close' => ["[a\n", 'toml: line 1: expected ] after table name'];

        yield 'array header with single close' => ["[[a]\n", 'toml: line 1: expected ]] after table name'];

        yield 'header descends into a scalar' => ["a = 1\n[a.b]\n", 'toml: line 2: key a is not a table'];

        yield 'table defined twice' => ["[a]\n[a]\n", self::TABLE_A_DEFINED];

        yield 'table over a scalar' => ["a = 1\n[a]\n", self::TABLE_A_DEFINED];

        yield 'table header repeated after implicit definition' => ["[a.b]\n[a]\n[a]\n", 'toml: line 3: table a is already defined'];

        yield 'table over dotted key table' => ["a.b = 1\n[a]\n", self::TABLE_A_DEFINED];

        yield 'array of tables over a scalar' => ["a = 1\n[[a]]\n", 'toml: line 2: key a is not an array of tables'];

        yield 'array of tables over a table' => ["[a]\n[[a]]\n", 'toml: line 2: key a is not an array of tables'];

        yield 'key without equals' => ["a = 1\n\na\n", 'toml: line 3: expected = after key'];

        yield 'duplicate key' => ["a = 1\na = 2\n", 'toml: line 2: key a is already defined'];

        yield 'duplicate dotted key' => ["a = 1\nb.c = 1\nb.c = 2\n", 'toml: line 3: key c is already defined'];

        yield 'dotted key through a scalar' => ["a = 1\na.b = 2\n", 'toml: line 2: key a is not a table'];

        yield 'array without comma' => ["a = [1 2]\n", 'toml: line 1: expected , or ] in array'];

        yield 'missing value' => ["a = \n", self::EXPECTED_VALUE];

        yield 'comma as value' => ["a = ,\n", self::EXPECTED_VALUE];

        yield 'comment as value' => ["a = # c\n", self::EXPECTED_VALUE];

        yield 'bare word' => ["a = nope\n", 'toml: line 1: invalid value nope'];

        yield 'error line counts earlier lines' => ["\n\na = 1\n\n\nb = x\n", 'toml: line 6: invalid value x'];

        yield 'unterminated literal' => ["a = 'x\n", 'toml: line 1: unterminated literal string'];

        yield 'newline in literal' => ["a = 'x\ny'\n", 'toml: line 1: newline in literal string'];

        yield 'unterminated multi-line literal' => ["a = '''x\n", 'toml: line 1: unterminated multi-line literal string'];

        yield 'garbage after multi-line literal' => ["a = '''x''' '''\n", self::TRAILING];

        yield 'newline in basic string' => ["a = \"x\ny\"\n", 'toml: line 1: newline in string'];

        yield 'unterminated basic string' => ["a = \"x\n", 'toml: line 1: newline in string'];

        yield 'unterminated basic string at end' => ['a = "x', 'toml: line 1: unterminated string'];

        yield 'bad escape' => ["a = \"\\q\"\n", 'toml: line 1: invalid escape sequence \\q'];

        yield 'space escape in a single-line string' => ["a = \"a\\ b\"\n", 'toml: line 1: invalid escape sequence \\ '];

        yield 'tab escape in a single-line string' => ["a = \"a\\\tb\"\n", "toml: line 1: invalid escape sequence \\\t"];

        yield 'bad escape in a multi-line string' => ["a = \"\"\"\\q\"\"\"\n", 'toml: line 1: invalid escape sequence \\q'];

        yield 'short unicode escape' => ["a = \"\\u00e\"\n", 'toml: line 1: invalid unicode escape'];

        yield 'non-hex unicode escape' => ["a = \"\\u00zz\"\n", 'toml: line 1: invalid unicode escape'];

        yield 'unicode escape above the range' => ["a = \"\\U00110000\"\n", self::BAD_SCALAR];

        yield 'unicode escape at the first surrogate' => ["a = \"\\uD800\"\n", self::BAD_SCALAR];

        yield 'unicode escape at the last surrogate' => ["a = \"\\uDFFF\"\n", self::BAD_SCALAR];

        yield 'garbage after a value' => ["a = 1 2\n", self::TRAILING];

        yield 'six quotes close a multi-line string and leave garbage' => ["a = \"\"\"x\"\"\"\"\"\"\n", self::TRAILING];

        yield 'inline table without comma' => ["a = {b = 1 c = 2}\n", 'toml: line 1: expected , or } in inline table'];

        yield 'inline table without equals' => ["a = {b 1}\n", 'toml: line 1: expected = after key in inline table'];

        yield 'unterminated array' => ["a = [\n", 'toml: line 2: unterminated array'];

        yield 'unterminated array after a comma' => ["a = [1,\n", 'toml: line 2: unterminated array'];

        yield 'missing key' => ["= 1\n", 'toml: line 1: expected a key'];

        yield 'hex with a prefix' => ["a = x0xFF\n", 'toml: line 1: invalid value x0xFF'];

        yield 'hex with a suffix' => ["a = 0xFFg\n", 'toml: line 1: invalid value 0xFFg'];

        yield 'octal with a prefix' => ["a = x0o17\n", 'toml: line 1: invalid value x0o17'];

        yield 'octal with a suffix' => ["a = 0o17x\n", 'toml: line 1: invalid value 0o17x'];

        yield 'binary with a prefix' => ["a = x0b101\n", 'toml: line 1: invalid value x0b101'];

        yield 'binary with a suffix' => ["a = 0b101x\n", 'toml: line 1: invalid value 0b101x'];

        yield 'integer with a prefix' => ["a = x5\n", 'toml: line 1: invalid value x5'];

        yield 'integer with a suffix' => ["a = 5x\n", 'toml: line 1: invalid value 5x'];

        yield 'float with a prefix' => ["a = x1.5\n", 'toml: line 1: invalid value x1.5'];

        yield 'float with a suffix' => ["a = 1.5x\n", 'toml: line 1: invalid value 1.5x'];

        yield 'inf with a prefix' => ["a = xinf\n", 'toml: line 1: invalid value xinf'];

        yield 'nan with a suffix' => ["a = nanx\n", 'toml: line 1: invalid value nanx'];

        yield 'date with a prefix does not absorb the time' => ["a = x2020-01-01 10:00\n", 'toml: line 1: invalid value x2020-01-01'];

        yield 'date with a suffix does not absorb the time' => ["a = 2020-01-01x 10:00\n", 'toml: line 1: invalid value 2020-01-01x'];

        yield 'array item separated by a newline before the comma is fine but a missing comma is not' => ["a = [1\n2]\n", 'toml: line 2: expected , or ] in array'];

        yield 'date followed by garbage' => ["a = 1979-05-27 07:32:00\nb = 1979-05-27 x\n", 'toml: line 2: unexpected characters after value'];
    }

    #[DataProvider('valueCases')]
    public function testValues(string $toml, string $expectedJson): void
    {
        $json = new JsonEncoder()->encode(new TomlParser($toml)->parse(), new FormatOptions(indent: 0, unwrapScalar: false), 0);

        self::assertSame($expectedJson . "\n", $json);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valueCases(): iterable
    {
        yield 'binary integers of every digit count' => [
            "a = 0b0\nb = 0b1\nc = 0b11\nd = 0b101\ne = 0b1111\nf = 0b100000\ng = 0b1000000\nh = 0b11111111\ni = 0b1_0\nj = 0b1111111111111111\n",
            '{"a":0,"b":1,"c":3,"d":5,"e":15,"f":32,"g":64,"h":255,"i":2,"j":65535}',
        ];

        yield 'binary integers with leading zeros' => ["a = 0b0001\nb = 0b00000111\n", '{"a":1,"b":7}'];

        yield 'multi-line literal strings' => [
            "a = '''\r\nx'''\nb = '''\nx'''\nc = '''x''''\nd = ''''x'''''\ne = '''\r\n'''\nf = ''''''\n",
            '{"a":"x","b":"x","c":"x\'","d":"\'x\'\'","e":"","f":""}',
        ];

        yield 'empty literal string' => ["a = ''\nb = ''''''\n", '{"a":"","b":""}'];

        yield 'multi-line literal keeps a carriage return that is not part of the first line break' => ["a = '''\rx'''\n", "{\"a\":\"\\rx\"}"];

        yield 'multi-line basic strings' => [
            "a = \"\"\"\r\nx\"\"\"\nb = \"\"\"\nx\"\"\"\nc = \"\"\"x\"\"\"\"\nd = \"\"\"x\"\"\"\"\"\ne = \"\"\"\rx\"\"\"\n",
            '{"a":"x","b":"x","c":"x\"","d":"x\"\"","e":"\rx"}',
        ];

        yield 'line ending backslash' => [
            "a = \"\"\"a \\\n   b\"\"\"\nb = \"\"\"a \\\t\n b\"\"\"\nc = \"\"\"a \\\r\n b\"\"\"\nd = \"\"\"a\\\nb\"\"\"\ne = \"\"\"a \\ x\"\"\"\nf = \"\"\"a\\\n\n\n  b\"\"\"\n",
            '{"a":"a b","b":"a b","c":"a b","d":"ab","e":"a x","f":"ab"}',
        ];

        yield 'unicode escapes at their boundaries' => [
            "a = \"\\U0010FFFF\"\nb = \"\\uD7FF\"\nc = \"\\uE000\"\nd = \"\\u0041\"\n",
            '{"a":"' . "\u{10FFFF}" . '","b":"' . "\u{D7FF}" . '","c":"' . "\u{E000}" . '","d":"A"}',
        ];

        yield 'every simple escape' => ["a = \"\\b\\t\\n\\f\\r\\\"\\\\\"\n", '{"a":"\u0008\t\n\u000c\r\"\\\\"}'];

        yield 'dates' => ["a = 1979-05-27 07:32:00Z # c\nb = 1979-05-27\nc = 1979-05-27T07:32:00\n", '{"a":"1979-05-27 07:32:00Z","b":"1979-05-27","c":"1979-05-27T07:32:00"}'];

        yield 'empty inline tables' => ["a = {}\nb = {c = {}}\n", '{"a":{},"b":{"c":{}}}'];

        yield 'indented table header' => ["  [a]\nx = 1\n \t[[b]]\ny = 2\n", '{"a":{"x":1},"b":[{"y":2}]}'];

        yield 'array separators on their own lines' => ["a = [1 , 2 ]\nb = [\n1\n,\n2\n]\n", '{"a":[1,2],"b":[1,2]}'];

        yield 'inline table with blanks and newlines' => ["a = { }\nb = {\n c = 1,\n d = 2\n}\n", '{"a":{},"b":{"c":1,"d":2}}'];

        yield 'explicit plus signs are dropped' => ["a = +5\nb = +1.5\nc = +1e3\n", '{"a":5,"b":1.5,"c":1e3}'];

        yield 'octal and hex' => ["a = 0o17\nb = 0xFF\nc = 0xdead_beef\n", '{"a":15,"b":255,"c":3735928559}'];
    }

    public function testCommentsLoseTheirTrailingWhitespace(): void
    {
        $root = new TomlParser("# head  \t\na = 1 # tail \t \n")->parse()->root();

        self::assertSame('# head', $root->content[0]->headComment);
        self::assertSame('# tail', $root->content[1]->lineComment);
    }

    public function testTableHeadCommentsAreAttachedToTheKey(): void
    {
        $root = new TomlParser("# c\n[a]\nx = 1\n[b]\n# hd\n[b.c]\n")->parse()->root();

        self::assertSame('# c', $root->content[0]->headComment);
        self::assertSame('', $root->content[2]->headComment);
        self::assertSame('# hd', $root->content[3]->content[0]->headComment);
    }

    public function testCommentOnAnImplicitTableIsKeptWhenItIsDefinedLater(): void
    {
        $root = new TomlParser("[a.b]\n# note\n[a]\nx = 1\n")->parse()->root();

        self::assertSame('# note', $root->content[0]->headComment);
    }

    public function testImplicitTableWithoutCommentKeepsItsKeyCommentEmpty(): void
    {
        $root = new TomlParser("[a.b]\n[a]\nx = 1\n")->parse()->root();

        self::assertSame('', $root->content[0]->headComment);
    }

}
