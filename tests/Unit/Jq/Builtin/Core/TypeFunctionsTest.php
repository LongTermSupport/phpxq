<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\TypeFunctions;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TypeFunctionsTest extends TestCase
{
    #[DataProvider('lengths')]
    public function testLength(string $json, mixed $expected): void
    {
        self::assertSame($expected, Harness::call('length', Harness::json($json)));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function lengths(): iterable
    {
        yield 'null' => ['null', 0];
        yield 'string counts codepoints' => ['"héllo 😀"', 7];
        yield 'empty array' => ['[]', 0];
        yield 'array' => ['[1,2,3]', 3];
        yield 'object' => ['{"a":1,"b":2}', 2];
        yield 'number is its absolute value' => ['-5', 5];
        yield 'fraction' => ['-1.5', 1.5];
    }

    public function testLengthKeepsPreciseNumberDigits(): void
    {
        $length = Harness::call('length', Harness::json('-100000000000000000000'));

        self::assertInstanceOf(PreciseNumber::class, $length);
        self::assertSame('100000000000000000000', $length->literal);
    }

    public function testLengthOfBooleanFails(): void
    {
        self::assertSame('boolean (true) has no length', Harness::error('length', true));
    }

    public function testUtf8ByteLength(): void
    {
        self::assertSame(6, Harness::call('utf8bytelength', "asdf\u{3bc}"));
        self::assertSame('array ([1,2]) only strings have UTF-8 byte length', Harness::error('utf8bytelength', [1, 2]));
    }

    public function testTypeAndNot(): void
    {
        self::assertSame('object', Harness::call('type', new JsonObject()));
        self::assertSame('number', Harness::call('type', 1.5));
        self::assertTrue(Harness::call('not', null));
        self::assertTrue(Harness::call('not', false));
        self::assertFalse(Harness::call('not', 0));
    }

    public function testKeysAreSortedByCodepoint(): void
    {
        self::assertSame(['Foo', 'abc', 'abcd'], Harness::call('keys', Harness::json('{"abcd":1,"abc":2,"Foo":3}')));
        self::assertSame([0, 1, 2], Harness::call('keys', [42, 3, 35]));
        self::assertSame('number (5) has no keys', Harness::error('keys', 5));
    }

    public function testKeysUnsortedKeepInsertionOrder(): void
    {
        self::assertSame(['b', 'a', '10', '9'], Harness::call('keys_unsorted', Harness::json('{"b":1,"a":2,"10":3,"9":4}')));
        self::assertSame([0, 1], Harness::call('keys_unsorted', ['x', 'y']));
        self::assertSame('null (null) has no keys', Harness::error('keys_unsorted', null));
    }

    public function testHas(): void
    {
        $object = Harness::json('{"foo":42}');
        self::assertTrue(Harness::call('has', $object, ['foo']));
        self::assertFalse(Harness::call('has', $object, ['bar']));
        self::assertTrue(Harness::call('has', ['a', 'b', 'c'], [2]));
        self::assertFalse(Harness::call('has', ['a', 'b', 'c'], [3]));
        self::assertFalse(Harness::call('has', ['a'], [-1]));
        self::assertFalse(Harness::call('has', [0, 1, 2], [\NAN]));
    }

    public function testHasWithWrongKeyKind(): void
    {
        self::assertSame('Cannot check whether object has a number key', Harness::error('has', new JsonObject(), [1]));
        self::assertSame('Cannot check whether array has a string key', Harness::error('has', [], ['a']));
        self::assertSame('Cannot check whether null has a string key', Harness::error('has', null, ['a']));
    }

    #[DataProvider('containment')]
    public function testContains(string $haystack, string $needle, bool $expected): void
    {
        self::assertSame($expected, Harness::call('contains', Harness::json($haystack), [Harness::json($needle)]));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function containment(): iterable
    {
        yield 'substring' => ['"foobar"', '"bar"', true];
        yield 'not a substring' => ['"foo"', '"foobar"', false];
        yield 'empty string' => ['"abc"', '""', true];
        yield 'string with NUL' => ['"ab\u0000cd"', '"b\u0000c"', true];
        yield 'array subset' => ['["foobar","foobaz","blarp"]', '["baz","bar"]', true];
        yield 'array not subset' => ['["foobar","foobaz","blarp"]', '["bazzzzz","bar"]', false];
        yield 'array numbers' => ['[1,2,3]', '[3,1]', true];
        yield 'object subset' => ['{"foo":12,"bar":[1,2,{"barp":12,"blip":13}]}', '{"foo":12,"bar":[{"barp":12}]}', true];
        yield 'object mismatch' => ['{"foo":12,"bar":[1,2,{"barp":12,"blip":13}]}', '{"foo":12,"bar":[{"barp":15}]}', false];
        yield 'missing key' => ['{"foo":12,"bar":13}', '{"baz":14}', false];
        yield 'empty object' => ['{"foo":12}', '{}', true];
        yield 'equal numbers' => ['1', '1', true];
        yield 'different numbers' => ['1', '2', false];
        yield 'nested object' => ['{"foo":{"baz":12,"blap":{"bar":13}},"bar":14}', '{"bar":14,"foo":{"blap":{}}}', true];
        yield 'mixed kinds inside arrays never match' => ['[1,"1"]', '["1",1]', true];
        yield 'kind mismatch inside array' => ['[true]', '[false]', false];
    }

    public function testContainsOfDifferentKindsFails(): void
    {
        self::assertSame('string ("a") and number (1) cannot have their containment checked', Harness::error('contains', 'a', [1]));
        self::assertSame('boolean (true) and boolean (false) cannot have their containment checked', Harness::error('contains', true, [false]));
    }

    public function testContainsTooDeepFails(): void
    {
        $deep = [];
        for ($i = 0; $i < 10001; ++$i) {
            $deep = [$deep];
        }

        self::assertSame('Containment check too deep', Harness::error('contains', $deep, [$deep]));
        self::assertTrue(TypeFunctions::containsChecked([[]], [[]]));
    }

    public function testToJsonAndToString(): void
    {
        self::assertSame('{"a":[1,"x"]}', Harness::call('tojson', Harness::json('{"a":[1,"x"]}')));
        self::assertSame('"foo"', Harness::call('tojson', 'foo'));
        self::assertSame('foo', Harness::call('tostring', 'foo'));
        self::assertSame('[1]', Harness::call('tostring', [1]));
        self::assertSame('13911860366432393', Harness::call('tostring', Harness::json('13911860366432393')));
    }

    public function testFromJson(): void
    {
        self::assertSame([1, 'a'], Harness::call('fromjson', '[1,"a"]'));
        self::assertNan(Harness::call('fromjson', 'nan'));
        self::assertSame("Invalid string literal; expected \", but got ' at line 1, column 5 (while parsing '{'a': 123}')", Harness::error('fromjson', "{'a': 123}"));
        self::assertSame('number (1) only strings can be parsed', Harness::error('fromjson', 1));
    }

    #[DataProvider('numbers')]
    public function testToNumber(mixed $input, mixed $expected): void
    {
        $result = Harness::call('tonumber', $input);
        if (null === $expected) {
            self::assertEquals(1000, $result instanceof PreciseNumber ? $result->value : $result);

            return;
        }

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function numbers(): iterable
    {
        yield 'integer text' => ['10', 10];
        yield 'fraction' => ['6.7', 6.7];
        yield 'leading dot' => ['.89', 0.89];
        yield 'negative' => ['-876', -876];
        yield 'explicit plus' => ['+5.43', 5.43];
        yield 'exponent' => ['1e3', null];
        yield 'number stays' => [21, 21];
    }

    #[DataProvider('invalidNumbers')]
    public function testToNumberRejects(string $text): void
    {
        self::assertSame(
            \sprintf('string (%s) cannot be parsed as a number', json_encode($text, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)),
            Harness::error('tonumber', $text),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'trailing letter' => ['2a'];
        yield 'leading space' => [' 4'];
        yield 'trailing space' => ['5 '];
        yield 'empty' => [''];
        yield 'just a sign' => ['-'];
        yield 'embedded NUL' => ["123\u{0}456"];
    }

    public function testToNumberRejectsNonStrings(): void
    {
        self::assertSame('array ([]) cannot be parsed as a number', Harness::error('tonumber', []));
        self::assertSame('null (null) cannot be parsed as a number', Harness::error('tonumber', null));
    }

    public function testToNumberAcceptsNan(): void
    {
        self::assertNan(Harness::call('tonumber', 'nan'));
    }

    public function testToBoolean(): void
    {
        self::assertTrue(Harness::call('toboolean', 'true'));
        self::assertFalse(Harness::call('toboolean', 'false'));
        self::assertTrue(Harness::call('toboolean', true));
        self::assertSame('string ("tru") cannot be parsed as a boolean', Harness::error('toboolean', 'tru'));
        self::assertSame('null (null) cannot be parsed as a boolean', Harness::error('toboolean', null));
    }

    public function testToArray(): void
    {
        self::assertSame([1, 2], Harness::call('toarray', [1, 2]));
        self::assertSame([1], Harness::call('toarray', 1));
        self::assertSame([null], Harness::call('toarray', null));
    }

    public function testAbs(): void
    {
        self::assertSame(10, Harness::call('abs', -10));
        self::assertSame(1.1, Harness::call('abs', -1.1));
        self::assertSame(0, Harness::call('abs', -0.0));
        self::assertSame('abc', Harness::call('abs', 'abc'));
        self::assertNull(Harness::call('abs', null));
        $big = Harness::call('abs', Harness::json('-100000000000000000000'));
        self::assertInstanceOf(PreciseNumber::class, $big);
        self::assertSame('100000000000000000000', $big->literal);
    }

    public function testAscii(): void
    {
        self::assertSame('A', Harness::call('ascii', 65));
        self::assertSame('ascii only takes numbers between 0 and 127', Harness::error('ascii', 128));
        self::assertSame('ascii only takes numbers between 0 and 127', Harness::error('ascii', 'x'));
    }

    public function testFloatClassification(): void
    {
        self::assertInfinite(Harness::call('infinite', null));
        self::assertNan(Harness::call('nan', null));
        self::assertTrue(Harness::call('isinfinite', \INF));
        self::assertFalse(Harness::call('isinfinite', 1));
        self::assertTrue(Harness::call('isnan', \NAN));
        self::assertFalse(Harness::call('isnan', 1));
        self::assertTrue(Harness::call('isnormal', 1));
        self::assertFalse(Harness::call('isnormal', 0));
        self::assertFalse(Harness::call('isnormal', 5.0e-324));
        self::assertFalse(Harness::call('isnormal', \INF));
        self::assertFalse(Harness::call('isnormal', \NAN));
        self::assertSame('string ("a") number required', Harness::error('isnan', 'a'));
    }

    public function testBuildCapabilities(): void
    {
        self::assertTrue(Harness::call('have_literal_numbers', null));
        self::assertTrue(Harness::call('have_decnum', null));
    }
}
