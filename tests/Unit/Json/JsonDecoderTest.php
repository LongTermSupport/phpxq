<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use Generator;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\JsonSyntaxException;
use LTS\PhpXq\Json\PreciseNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @internal
 */
final class JsonDecoderTest extends TestCase
{
    private const string BOM = "\xEF\xBB\xBF";

    #[DataProvider('valueProvider')]
    public function testDecodeOne(string $json, string $expected): void
    {
        self::assertSame($expected, self::describe(new JsonDecoder()->decodeOne($json)));
    }

    #[DataProvider('valueProvider')]
    public function testScannerAndFastPathAgree(string $json, string $expected): void
    {
        // the scanner alone must give the same result as the native fast path
        $method = new ReflectionMethod(JsonDecoder::class, 'scan');
        $values = $method->invoke(new JsonDecoder(), $json, false, 0);
        self::assertInstanceOf(Generator::class, $values);
        $slow = iterator_to_array($values, false);

        self::assertCount(1, $slow);
        self::assertSame($expected, self::describe($slow[0]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valueProvider(): iterable
    {
        yield 'null'                  => ['null', 'null'];
        yield 'true'                  => ['true', 'true'];
        yield 'false'                 => ['false', 'false'];
        yield 'int'                   => ['42', 'int:42'];
        yield 'negative int'          => ['-7', 'int:-7'];
        yield 'float'                 => ['1.5', 'float:1.5'];
        yield 'string'                => ['"abc"', 'string:abc'];
        yield 'empty array'           => ['[]', '[]'];
        yield 'empty object'          => ['{}', '{}'];
        yield 'nested'                => ['{"a":[1,{"b":null}],"c":"d"}', '{a=>[int:1,{b=>null}],c=>string:d}'];
        yield 'whitespace everywhere' => [" \t\r\n[ 1 ,\n 2 ] \n", '[int:1,int:2]'];
        yield 'duplicate key last wins' => ['{"a":1,"b":2,"a":3}', '{a=>int:3,b=>int:2}'];
        yield 'numeric string keys'   => ['{"1":"x","01":"y","-1":"z"}', '{1=>string:x,01=>string:y,-1=>string:z}'];
        yield 'empty key'             => ['{"":1}', '{=>int:1}'];
        yield 'escapes'               => ['"a\"b\\\c\/d\b\f\n\r\t"', "string:a\"b\\c/d\x08\x0c\n\r\t"];
        yield 'unicode escape'        => ['"\u00e9\u20ac"', "string:\u{e9}\u{20ac}"];
        yield 'surrogate pair'        => ['"\ud83d\ude00"', "string:\u{1f600}"];
        yield 'nul escape'            => ['"a\u0000b"', "string:a\x00b"];
        yield 'lone low surrogate'    => ['"\udc00"', "string:\u{fffd}"];
        yield 'raw utf8'              => ["\"\u{e9}\u{1f600}\"", "string:\u{e9}\u{1f600}"];
        yield 'invalid utf8'          => ["\"a\xffb\"", "string:a\u{fffd}b"];
        yield 'truncated utf8'        => ["\"a\xe2\x82\"", "string:a\u{fffd}"];
        yield 'del is plain'          => ['""', "string:\x7f"];
        yield 'bom'                   => [self::BOM . '[1]', '[int:1]'];
        yield 'nan'                   => ['nan', 'float:NAN'];
        yield 'NaN'                   => ['NaN', 'float:NAN'];
        yield 'negative NaN'          => ['-NaN', 'float:NAN'];
        yield 'nan inside'            => ['[nan,1]', '[float:NAN,int:1]'];
        yield 'Infinity'              => ['Infinity', 'float:INF'];
        yield 'negative Infinity'     => ['-Infinity', 'float:-INF'];
        yield 'infinity lower'        => ['infinity', 'float:INF'];
        yield 'leading zeros'         => ['01', 'int:1'];
        yield 'plus sign'             => ['+1', 'int:1'];
        yield 'leading point'         => ['.5', 'float:0.5'];
        yield 'negative zero'         => ['-0', 'float:-0'];
        yield 'preserved decimals'    => ['[1, 1.000, 1.0, 100e-2]', '[int:1,precise:1.000,precise:1.0,precise:1.00]'];
        yield 'big integer'           => ['12345678909876543212345', 'precise:12345678909876543212345'];
        yield 'long fraction'         => ['0.12345678901234567890123456789', 'precise:0.12345678901234567890123456789'];
        yield 'beyond 2^53'           => ['[9007199254740992,9007199254740993]', '[int:9007199254740992,precise:9007199254740993]'];
        yield 'exponent'              => ['1e1000', 'precise:1E+1000'];
        yield 'small exponent'        => ['1e-5', 'precise:0.00001'];
        yield 'seventeen digits'      => ['0.30000000000000004', 'float:0.30000000000000004'];
        yield 'null in array'         => ['[null,null]', '[null,null]'];
        yield 'deep key order'        => ['{"z":1,"a":2,"m":{"y":1,"b":2}}', '{z=>int:1,a=>int:2,m=>{y=>int:1,b=>int:2}}'];
    }

    #[DataProvider('errorProvider')]
    public function testSyntaxErrorMessages(string $json, string $message): void
    {
        $decoder = new JsonDecoder();
        try {
            iterator_to_array($decoder->decodeAll($json), false);

            self::fail('expected a syntax error for ' . $json);
        } catch (JsonSyntaxException $jsonSyntaxException) {
            self::assertSame($message, $jsonSyntaxException->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorProvider(): iterable
    {
        yield 'missing value'            => ['{"a":}', "Unmatched '}' at line 1, column 6"];
        yield 'brace in array'           => ['[1}', 'Objects must consist of key:value pairs at line 1, column 3'];
        yield 'empty array close'        => ['[}', "Unmatched '}' at line 1, column 2"];
        yield 'trailing comma object'    => ['{"a":1,}', 'Expected another key-value pair at line 1, column 8'];
        yield 'missing colon'            => ['{"a" 1}', 'Expected separator between values at line 1, column 7'];
        yield 'trailing comma array'     => ['[1,]', 'Expected another array element at line 1, column 4'];
        yield 'top level comma'          => ['1,2', "Expected value before ',' at line 1, column 2"];
        yield 'lone comma'               => [',', "Expected value before ',' at line 1, column 1"];
        yield 'comma in empty object'    => ['{,}', "Expected value before ',' at line 1, column 2"];
        yield 'double comma'             => ['[1,,2]', "Expected value before ',' at line 1, column 4"];
        yield 'lone close bracket'       => [']', "Unmatched ']' at line 1, column 1"];
        yield 'lone close brace'         => ['}', "Unmatched '}' at line 1, column 1"];
        yield 'bracket in object'        => ['{]', "Unmatched ']' at line 1, column 2"];
        yield 'bracket after member'     => ['{"a":1]', "Unmatched ']' at line 1, column 7"];
        yield 'missing comma'            => ['[1 2]', 'Expected separator between values at line 1, column 5'];
        yield 'missing comma strings'    => ['[1,"a" "b"]', 'Expected separator between values at line 1, column 10'];
        yield 'truncated true'           => ['tru', 'Invalid literal at EOF at line 1, column 3'];
        yield 'truncated null'           => ['nul', 'Invalid literal at EOF at line 1, column 3'];
        yield 'truncated true in array'  => ['[tru]', 'Invalid literal at line 1, column 5'];
        yield 'trailing junk on literal' => ['true1', 'Invalid literal at EOF at line 1, column 5'];
        yield 'glued literals'           => ['truefalse', 'Invalid literal at EOF at line 1, column 9'];
        yield 'unfinished array'         => ['[1,2', 'Unfinished JSON term at EOF at line 1, column 4'];
        yield 'unfinished object'        => ['{"a"', 'Unfinished JSON term at EOF at line 1, column 4'];
        yield 'unfinished open brace'    => ['{', 'Unfinished JSON term at EOF at line 1, column 1'];
        yield 'unfinished after colon'   => ['{"a":', 'Unfinished JSON term at EOF at line 1, column 5'];
        yield 'unfinished after comma'   => ['{"a":1,', 'Unfinished JSON term at EOF at line 1, column 7'];
        yield 'unfinished with newline'  => ["[1,\n", 'Unfinished JSON term at EOF at line 2, column 0'];
        yield 'unfinished string'        => ['"abc', 'Unfinished string at EOF at line 1, column 4'];
        yield 'unfinished escape'        => ['"\\', 'Unfinished string at EOF at line 1, column 2'];
        yield 'dangling exponent'        => ['1e', 'Invalid numeric literal at EOF at line 1, column 2'];
        yield 'dangling exponent sign'   => ['1e+', 'Invalid numeric literal at EOF at line 1, column 3'];
        yield 'two points'               => ['1.5.5', 'Invalid numeric literal at EOF at line 1, column 5'];
        yield 'double minus'             => ['--1', 'Invalid numeric literal at EOF at line 1, column 3'];
        yield 'lone minus'               => ['-', 'Invalid numeric literal at EOF at line 1, column 1'];
        yield 'letters'                  => ['123abc', 'Invalid numeric literal at EOF at line 1, column 6'];
        yield 'nan payload'              => ['NaN1', 'Invalid numeric literal at EOF at line 1, column 4'];
        yield 'nan payload long'         => ['NaN100000', 'Invalid numeric literal at EOF at line 1, column 9'];
        yield 'single quoted string'     => ["{'a': 123}", "Invalid string literal; expected \", but got ' at line 1, column 5"];
        yield 'number key'               => ['{1:2}', 'Object keys must be strings at line 1, column 3'];
        yield 'key without value'        => ['{"a"}', 'Objects must consist of key:value pairs at line 1, column 5'];
        yield 'key comma'                => ['{"a",1}', 'Objects must consist of key:value pairs at line 1, column 5'];
        yield 'member without key'       => ['{"a":1,2}', 'Objects must consist of key:value pairs at line 1, column 9'];
        yield 'colon in array'           => ['[:]', "Expected string key before ':' at line 1, column 2"];
        yield 'colon first in object'    => ['{:1}', "Expected string key before ':' at line 1, column 2"];
        yield 'double colon'             => ['{"a"::1}', "Expected string key before ':' at line 1, column 6"];
        yield 'second colon'             => ['{"a":1:2}', "':' not as part of an object at line 1, column 7"];
        yield 'bad escape'               => ['"\x"', 'Invalid escape at line 1, column 4'];
        yield 'bad escape v'             => ['"\v"', 'Invalid escape at line 1, column 4'];
        yield 'short unicode escape'     => ['"\u12"', 'Invalid \uXXXX escape at line 1, column 6'];
        yield 'bad hex'                  => ['"\u12G4"', 'Invalid characters in \uXXXX escape at line 1, column 8'];
        yield 'lone high surrogate'      => ['"\ud800A"', 'Invalid \uXXXX\uXXXX surrogate pair escape at line 1, column 9'];
        yield 'high then non low'        => ['"\ud800\u0041"', 'Invalid \uXXXX\uXXXX surrogate pair escape at line 1, column 14'];
        yield 'raw tab'                  => ["\"a\tb\"", 'Invalid string: control characters from U+0000 through U+001F must be escaped at line 1, column 5'];
        yield 'raw newline in string'    => ["\"a\nb\"", 'Invalid string: control characters from U+0000 through U+001F must be escaped at line 2, column 2'];
        yield 'raw 0x1f'                 => ["\"\x1f\"", 'Invalid string: control characters from U+0000 through U+001F must be escaped at line 1, column 3'];
        yield 'raw nul'                  => ["\"\x00\"", 'Invalid string: control characters from U+0000 through U+001F must be escaped at line 1, column 3'];
        yield 'error on third line'      => ["[\n1,\nx]", 'Invalid numeric literal at line 3, column 2'];
        yield 'second value broken'      => ["1\n2\n}", "Unmatched '}' at line 3, column 1"];
        yield 'error after bom'          => [self::BOM . '[1,}', "Unmatched '}' at line 1, column 4"];
        yield 'bom then second line'     => [self::BOM . "1\n}", "Unmatched '}' at line 2, column 1"];
        yield 'bom odd first line'       => [self::BOM . "1.0\n2\n}", "Unmatched '}' at line 3, column 1"];
        yield 'malformed bom'            => ["\xEF\xBB[1]", 'Malformed BOM'];
        yield 'depth limit arrays'       => [str_repeat('[', 10001), 'Exceeds depth limit for parsing at line 1, column 10001'];
        yield 'depth limit objects'      => [str_repeat('{"a":', 5001), 'Exceeds depth limit for parsing at line 1, column 25001'];
        yield 'form feed is not space'   => ["1\x0c", 'Invalid numeric literal at EOF at line 1, column 2'];
    }

    #[DataProvider('deepOkProvider')]
    public function testDepthWithinLimitDecodes(string $json): void
    {
        $values = iterator_to_array(new JsonDecoder()->decodeAll($json), false);

        self::assertCount(1, $values);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deepOkProvider(): iterable
    {
        yield 'ten thousand arrays' => [str_repeat('[', 10000) . str_repeat(']', 10000)];
        yield 'five thousand objects' => [str_repeat('{"a":', 5000) . '1' . str_repeat('}', 5000)];
    }

    public function testValuesBeforeAnErrorAreYieldedFirst(): void
    {
        [$seen, $error] = $this->collect('1 "a" [2] }');

        self::assertSame(['int:1', 'string:a', '[int:2]'], $seen);
        self::assertSame("Unmatched '}' at line 1, column 11", $error);
    }

    public function testStringValueIsEmittedBeforeALaterColonErrors(): void
    {
        [$seen, $error] = $this->collect('"a":1');

        self::assertSame(['string:a'], $seen);
        self::assertSame("Expected string key before ':' at line 1, column 4", $error);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('streamProvider')]
    public function testDecodeAll(string $text, array $expected): void
    {
        $values = array_map(self::describe(...), iterator_to_array(new JsonDecoder()->decodeAll($text), false));

        self::assertSame($expected, $values);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function streamProvider(): iterable
    {
        yield 'empty'                 => ['', []];
        yield 'blank'                 => [" \n\t ", []];
        yield 'bom only'              => [self::BOM, []];
        yield 'numbers'               => ['1 2 3', ['int:1', 'int:2', 'int:3']];
        yield 'mixed'                 => ['1 2 [3]{"a":4}"x""y"', ['int:1', 'int:2', '[int:3]', '{a=>int:4}', 'string:x', 'string:y']];
        yield 'booleans'              => ['true false', ['true', 'false']];
        yield 'quote after number'    => ['1"a"', ['int:1', 'string:a']];
        yield 'ndjson'                => ["{\"a\":1}\n{\"a\":2}\n\n{\"a\":3}\n", ['{a=>int:1}', '{a=>int:2}', '{a=>int:3}']];
        yield 'ndjson crlf'           => ["1\r\n2\r\n", ['int:1', 'int:2']];
        yield 'ndjson pretty'         => ["{\n  \"a\": 1\n}\n{\n  \"b\": 2\n}\n", ['{a=>int:1}', '{b=>int:2}']];
        yield 'ndjson mixed lines'    => ["1\n[2,\n3]\n4 5\n6", ['int:1', '[int:2,int:3]', 'int:4', 'int:5', 'int:6']];
        yield 'ndjson with big'       => ["1\n12345678901234567890\n2.50\n", ['int:1', 'precise:12345678901234567890', 'precise:2.50']];
        yield 'ndjson bom first'      => [self::BOM . "1\n2", ['int:1', 'int:2']];
        yield 'multiple floats'       => ["1.5\n0.25\n-3.75", ['float:1.5', 'float:0.25', 'float:-3.75']];
    }

    public function testDecodeAllKeysAreSequential(): void
    {
        $keys = array_keys(iterator_to_array(new JsonDecoder()->decodeAll("1\n2\n[3,\n4]\n5")));

        self::assertSame([0, 1, 2, 3], $keys);
    }

    public function testErrorAfterFastLinesKeepsAbsolutePosition(): void
    {
        [$seen, $error] = $this->collect("1\n2\n3 x\n");

        self::assertSame(['int:1', 'int:2', 'int:3'], $seen);
        self::assertSame('Invalid numeric literal at line 4, column 0', $error);
    }

    public function testDecodeOneAddsTheWhileParsingSuffix(): void
    {
        $decoder = new JsonDecoder();

        try {
            $decoder->decodeOne("{'a': 123}");
            self::fail('expected a syntax error');
        } catch (JsonSyntaxException $jsonSyntaxException) {
            self::assertSame("Invalid string literal; expected \", but got ' at line 1, column 5 (while parsing '{'a': 123}')", $jsonSyntaxException->getMessage());
        }
    }

    public function testDecodeOneRejectsEmptyInput(): void
    {
        self::assertSame("Expected JSON value (while parsing '  ')", $this->failure(static fn (): mixed => new JsonDecoder()->decodeOne('  ')));
    }

    public function testDecodeOneRejectsExtraValues(): void
    {
        self::assertSame("Unexpected extra JSON values (while parsing '1 2')", $this->failure(static fn (): mixed => new JsonDecoder()->decodeOne('1 2')));
    }

    public function testDecodeOneReportsErrorAfterTheFirstValue(): void
    {
        self::assertSame("Unmatched '}' at line 1, column 3 (while parsing '1 }')", $this->failure(static fn (): mixed => new JsonDecoder()->decodeOne('1 }')));
    }

    public function testTryDecodeOneReturnsAValidValue(): void
    {
        $ok    = false;
        $value = new JsonDecoder()->tryDecodeOne(' [1, 2.0, 12345678901234567890] ', $ok);

        self::assertTrue($ok);
        self::assertIsArray($value);
        self::assertCount(3, $value);

        $ok = false;
        self::assertNull(new JsonDecoder()->tryDecodeOne('null', $ok));
        self::assertTrue($ok);
    }

    #[DataProvider('invalidSingleValues')]
    public function testTryDecodeOneRejectsWithoutThrowing(string $text): void
    {
        $ok = true;

        self::assertNull(new JsonDecoder()->tryDecodeOne($text, $ok));
        self::assertFalse($ok);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSingleValues(): iterable
    {
        yield 'empty' => ['  '];

        yield 'extra values' => ['1 2'];

        yield 'trailing close' => ['1 }'];

        yield 'bad literal' => ['tru'];

        yield 'bad number' => ['1.0x'];

        yield 'unfinished' => ['[1,'];

        yield 'malformed BOM' => ["\xEF\xBB"];
    }

    public function testLineThatNeedsTheScannerKeepsTheWholeTextError(): void
    {
        [$seen, $error] = $this->collect("1.0\n2.0\n[3.0,\n");

        self::assertCount(2, $seen);
        self::assertSame('Unfinished JSON term at EOF at line 4, column 0', $error);

        [, $error] = $this->collect("1.0\n[2.0\n3\n");

        self::assertSame('Expected separator between values at line 4, column 0', $error);
    }

    public function testObjectsAreJsonObjects(): void
    {
        $value = new JsonDecoder()->decodeOne('{"b":1,"a":{"2":[]}}');

        self::assertInstanceOf(JsonObject::class, $value);
        self::assertSame(['b', 'a'], $value->keys());
        $inner = $value->get('a');
        self::assertInstanceOf(JsonObject::class, $inner);
        self::assertSame(['2'], $inner->keys());
        self::assertSame([], $inner->get('2'));
    }

    public function testNanDecodesToNan(): void
    {
        $value = new JsonDecoder()->decodeOne('nan');

        self::assertIsFloat($value);
        self::assertNan($value);
    }

    /**
     * @param list<string> $values
     * @param list<string> $errors
     */
    #[DataProvider('seqProvider')]
    public function testSequenceMode(string $text, array $values, array $errors): void
    {
        $gotValues = [];
        $gotErrors = [];
        foreach (new JsonDecoder()->decodeAll($text, true) as $item) {
            if ($item instanceof JsonSyntaxException) {
                $gotErrors[] = $item->getMessage();

                continue;
            }

            $gotValues[] = self::describe($item);
        }

        self::assertSame($values, $gotValues);
        self::assertSame($errors, $gotErrors);
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function seqProvider(): iterable
    {
        yield 'plain'               => ["\x1e1\n\x1e[2]\n", ['int:1', '[int:2]'], []];
        yield 'leading junk'        => ["junk\x1e1\n", ['int:1'], []];
        yield 'jq shtest one'       => [
            "1\x1e2 3\n[0,1\x1e[4,5]true\"ab\"{\"c\":4\x1e{}{\"d\":5,\"e\":6\"\x1efalse\n",
            ['int:2', 'int:3', '[int:4,int:5]', 'true', 'string:ab', '{}', 'false'],
            ['Truncated value at line 2, column 5', 'Truncated value at line 2, column 25', 'Truncated value at line 2, column 41'],
        ];
        yield 'jq shtest two'       => [
            "1\x1e2 3\n[0,1\x1e[4,5]true\"ab\"{\"c\":4\x1e{}{\"d\":5,\"e\":6\"false\n\x1enull",
            ['int:2', 'int:3', '[int:4,int:5]', 'true', 'string:ab', '{}', 'null'],
            ['Truncated value at line 2, column 5', 'Truncated value at line 2, column 25', 'Truncated value at line 3, column 1'],
        ];
        yield 'unfinished string'   => ['"foo', [], ['Unfinished abandoned text at EOF at line 1, column 4']];
        yield 'unfinished number'   => ['1', [], ['Unfinished abandoned text at EOF at line 1, column 1']];
        yield 'number needs rs'     => ["1\n", [], ['Unfinished abandoned text at EOF at line 2, column 0']];
        yield 'resync after error'  => ["\x1e[1,}\x1e2\n", ['int:2'], ["Unmatched '}' at line 1, column 5 (need RS to resync)"]];
        yield 'truncated number'    => ["\x1e12\x1e3\n", ['int:3'], ['Potentially truncated top-level numeric value at line 1, column 4']];
        yield 'number at eof'       => ["\x1e12", [], ['Potentially truncated top-level numeric value at EOF at line 1, column 3']];
        yield 'value at eof'        => ["\x1e[1]", ['[int:1]'], []];
        yield 'blank'               => ['', [], []];
        yield 'whitespace'          => ["\n", [], []];
    }

    /**
     * @return array{list<string>, ?string}
     */
    private function collect(string $text): array
    {
        $seen = [];

        try {
            foreach (new JsonDecoder()->decodeAll($text) as $value) {
                $seen[] = self::describe($value);
            }
        } catch (JsonSyntaxException $jsonSyntaxException) {
            return [$seen, $jsonSyntaxException->getMessage()];
        }

        return [$seen, null];
    }

    /**
     * @param callable(): mixed $call
     */
    private function failure(callable $call): string
    {
        try {
            $call();
        } catch (JsonSyntaxException $jsonSyntaxException) {
            return $jsonSyntaxException->getMessage();
        }

        return 'no exception';
    }

    private static function describeFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        if (0.0 === $value && fdiv(1.0, $value) < 0) {
            return '-0';
        }

        return var_export($value, true);
    }

    private static function describe(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (true === $value) {
            return 'true';
        }

        if (false === $value) {
            return 'false';
        }

        if (\is_int($value)) {
            return 'int:' . $value;
        }

        if (\is_float($value)) {
            return 'float:' . self::describeFloat($value);
        }

        if ($value instanceof PreciseNumber) {
            return 'precise:' . $value->literal;
        }

        if (\is_string($value)) {
            return 'string:' . $value;
        }

        if (\is_array($value)) {
            return '[' . implode(',', array_map(self::describe(...), $value)) . ']';
        }

        self::assertInstanceOf(JsonObject::class, $value);
        $parts = [];
        foreach ($value->entries() as $key => $member) {
            $parts[] = $key . '=>' . self::describe($member);
        }

        return '{' . implode(',', $parts) . '}';
    }
}
