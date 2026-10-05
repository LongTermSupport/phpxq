<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\Codec\LuaReader;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LuaReaderTest extends TestCase
{
    private const string EXPECTED_NAME = 'lua: line 1: expected a name';

    private const string JSON_A1 = '{"a":1}';

    private const string BAD_HEX = 'lua: line 1: invalid \x escape';

    private const string TRAILING = 'lua: line 1: unexpected text after return value';

    private const string TABLE_SEPARATOR = 'lua: line 1: expected , or } in table';

    private const string CANNOT_NEGATE = 'lua: line 1: cannot negate this value';

    #[DataProvider('errorCases')]
    public function testErrorMessages(string $lua, string $expectedMessage): void
    {
        try {
            new LuaReader($lua)->read();
        } catch (FormatException $formatException) {
            self::assertSame($expectedMessage, $formatException->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorCases(): iterable
    {
        yield 'assignment without equals' => ['a 1', 'lua: line 1: expected = after a'];

        yield 'bare name' => ['a', 'lua: line 1: expected = after a'];

        yield 'second statement without equals' => ["a = 1\n\n b", 'lua: line 3: expected = after b'];

        yield 'two return values' => ['return 1 2', self::TRAILING];

        yield 'double semicolon after return' => ['return 1;;', self::TRAILING];

        yield 'return with nothing' => ['return', 'lua: line 1: unexpected end of input'];

        yield 'unknown name' => ['return foo', 'lua: line 1: unsupported expression foo'];

        yield 'name that starts like a keyword' => ['return truex', 'lua: line 1: unsupported expression truex'];

        yield 'negated name' => ['return -x', 'lua: line 1: unsupported expression x'];

        yield 'negated string' => ['return -"s"', self::CANNOT_NEGATE];

        yield 'negated numeric string' => ['return -"5"', self::CANNOT_NEGATE];

        yield 'negated nan' => ['return -(0/0)', self::CANNOT_NEGATE];

        yield 'negated nil' => ['return -nil', self::CANNOT_NEGATE];

        yield 'double minus is a comment' => ['return --5', 'lua: line 1: unexpected end of input'];

        yield 'divide by a string' => ['return 1/"a"', 'lua: line 1: cannot divide these values'];

        yield 'divide a string' => ['return "a"/1', 'lua: line 1: cannot divide these values'];

        yield 'unclosed parenthesis' => ['return (1', 'lua: line 1: expected )'];

        yield 'empty parentheses' => ['return ()', self::EXPECTED_NAME];

        yield 'unclosed table' => ['return {1,2', self::TABLE_SEPARATOR];

        yield 'table without separator' => ['return {a = 1 b = 2}', self::TABLE_SEPARATOR];

        yield 'unterminated table' => ['return {', 'lua: line 1: unterminated table'];

        yield 'unclosed bracket key' => ['return {[1 = 2}', 'lua: line 1: expected ]'];

        yield 'bracket key without equals' => ['return {[1] 2}', 'lua: line 1: expected ='];

        yield 'double equals in a table' => ['return {a==1}', 'lua: line 1: unsupported expression a'];

        yield 'two equals in a field' => ['return {a = = 1}', self::EXPECTED_NAME];

        yield 'name as bracket key' => ['return {[x]=1}', 'lua: line 1: unsupported expression x'];

        yield 'unbalanced long bracket' => ['return [= [x]]', 'lua: line 1: unexpected ['];

        yield 'unterminated long string' => ['return [[abc', 'lua: line 1: unterminated long string'];

        yield 'unterminated string' => ["return 'abc", 'lua: line 1: unterminated string'];

        yield 'newline in a string' => ["return 'a\nb'", 'lua: line 1: unterminated string'];

        yield 'short hex escape' => ['return "a\x4"', self::BAD_HEX];

        yield 'non-hex escape' => ['return "a\x41\x7a\xzz"', self::BAD_HEX];

        yield 'short unicode escape' => ['return "\u41"', 'lua: line 1: invalid \u escape'];

        yield 'unknown escape letter' => ['return "\q"', 'lua: line 1: invalid escape sequence \q'];

        yield 'unknown escape punctuation' => ['return "\:"', 'lua: line 1: invalid escape sequence \:'];

        yield 'hex number without digits' => ['return 0x', 'lua: line 1: unexpected text after return value'];

        yield 'unterminated block comment hides the value' => ["return --[[x\n 1", 'lua: line 2: unexpected end of input'];

        yield 'return semicolon without value' => ['return;', self::EXPECTED_NAME];

        yield 'assignment without a value' => ['a = =', self::EXPECTED_NAME];
    }

    #[DataProvider('valueCases')]
    public function testValues(string $lua, string $expectedJson): void
    {
        $json = new JsonEncoder()->encode(new LuaReader($lua)->read(), new FormatOptions(indent: 0, unwrapScalar: false), 0);

        self::assertSame($expectedJson . "\n", $json);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valueCases(): iterable
    {
        yield 'negated numbers' => ['return -5', '-5'];

        yield 'negated parenthesised number' => ['return -(1)', '-1'];

        yield 'double negation' => ['return - -5', '5'];

        yield 'negated negative' => ['return -(-5)', '5'];

        yield 'negated fraction' => ['return -.5', '-0.5'];

        yield 'division' => ['return 1/2', '0.5'];

        yield 'chained division' => ['return 1 / 2 / 4', '0.125'];

        yield 'division across a newline' => ["return 1 /\n 2", '0.5'];

        yield 'division by a negative' => ['return 1/ -2', '-0.5'];

        yield 'nil true false' => ['return {nil, true, false}', '[null,true,false]'];

        yield 'false alone' => ['return false', 'false'];

        yield 'digits at both ends' => ['return {0, 9, 09}', '[0,9,9]'];

        yield 'hex numbers are lower-cased' => ['return 0XfF', '255'];

        yield 'float shapes' => ['return {1e5, 1E-5, 1.5e+3, .5, 5., 3}', '[1e5,1E-5,1.5e+3,0.5,5.0,3]'];

        yield 'mixed table fields' => ['return {a = 1; b = 2, 3}', '{"a":1,"b":2,"1":3}'];

        yield 'bracket keys' => ['return {[1]=2, ["k"]=3, x=4, 5}', '{"1":2,"k":3,"x":4,"1":5}'];

        yield 'trailing comma' => ['return {a=1,}', self::JSON_A1];

        yield 'semicolon separators' => ['return {1,2;3}', '[1,2,3]'];

        yield 'comments inside a table' => ["return {--c\n 1 --d\n , --e\n 2}", '[1,2]'];

        yield 'nested tables' => ['return {{{1}}}', '[[[1]]]'];

        yield 'long strings' => ['return {[[long]], [==[x]==]}', '["long","x"]'];

        yield 'long string drops the first newline' => ["return [[\nabc]]", '"abc"'];

        yield 'long string drops the first carriage return and newline' => ["return [[\r\nabc]]", '"abc"'];

        yield 'long string keeps a second newline' => ["return [[\n\nabc]]", '"\nabc"'];

        yield 'long string with a shorter closing bracket inside' => ['return [==[ab]]cd]==]', '"ab]]cd"'];

        yield 'unicode escapes' => ['return "\u{41}\u{1F600}"', '"A' . "\u{1F600}" . '"'];

        yield 'hex escapes' => ['return "\x41\x7a\x412"', '"AzA2"'];

        yield 'decimal escapes' => ['return "\65\066\0067"', '"AB\u00067"'];

        yield 'decimal escape wraps at 256' => ['return "\256\65"', '"\u0000A"'];

        yield 'single digit decimal escapes' => ['return "\9\0"', '"\t\u0000"'];

        yield 'skip whitespace escape' => ['return "\z   b"', '"b"'];

        yield 'control escapes' => ['return "\a\b\f\n\r\t\v\\\\\"\\\'"', '"\u0007\u0008\u000c\n\r\t\u000b\\\\\"\'"'];

        yield 'escaped newline' => ["return 'a\\\nb'", '"a\nb"'];

        yield 'block comments' => ['return --[[x]] 1', '1'];

        yield 'leveled block comments' => ['return --[==[x]==] 1', '1'];

        yield 'line comment at the end' => ['return 1 -- end', '1'];

        yield 'semicolon after return' => ['return 1;', '1'];

        yield 'parentheses' => ['return ((1))', '1'];

        yield 'return directly followed by punctuation' => ['return(1)', '1'];

        yield 'return directly followed by a brace' => ['return{1}', '[1]'];

        yield 'return directly followed by a quote' => ['return"s"', '"s"'];

        yield 'assignments with comments and semicolons' => ["-- hi\na = 1\n--[[ x ]]\nb = 2;\n", '{"a":1,"b":2}'];

        yield 'assignments on one line' => ['a = 1 ; b = 2', '{"a":1,"b":2}'];

        yield 'trailing semicolon and blanks' => ["a = 1;  \n", self::JSON_A1];

        yield 'nested table assignment' => ["a = 1\nb = {x = 1}\n", '{"a":1,"b":{"x":1}}'];

        yield 'assignment names that start with return' => ["returnx = 1\nreturn_ = 2\n", '{"returnx":1,"return_":2}'];

        yield 'byte order mark before an assignment' => ["\u{FEFF}a = 1", self::JSON_A1];

        yield 'byte order mark before return' => ["\u{FEFF}return 1", '1'];
    }

    public function testInfinitiesAndNanUseYamlSpelling(): void
    {
        self::assertSame('.inf', new LuaReader('return 1/0')->read()->value);
        self::assertSame('-.inf', new LuaReader('return -1/0')->read()->value);
        self::assertSame('-.inf', new LuaReader('return -(1/0)')->read()->value);
        self::assertSame('.inf', new LuaReader('return -(-1/0)')->read()->value);
        self::assertSame('.nan', new LuaReader('return 0/0')->read()->value);
        self::assertSame('!!float', new LuaReader('return 1/0')->read()->tag);
    }

    public function testDecimalEscapeUpToTheByteMaximum(): void
    {
        self::assertSame("\xFF", new LuaReader('return "\255"')->read()->value);
    }

    public function testHexIntegersKeepTheirSpellingInLowerCase(): void
    {
        $node = new LuaReader('return 0XAB')->read();

        self::assertSame('0xab', $node->value);
        self::assertSame('!!int', $node->tag);
    }
}
