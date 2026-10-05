<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\Codec\JsonReader;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JsonReaderTest extends TestCase
{
    private const string END = 'json: unexpected end of JSON input';

    private const string BEGINNING_OF_VALUE = "json: invalid character '%s' looking for beginning of value";

    private const string AFTER_TOP_LEVEL = "json: invalid character '%s' after top-level value";

    private const string HUNDRED_THOUSAND = '100000';

    private const string SMALL_EXPONENT = '1e-05';

    private const string OBJECT_A = "{\"a\":1}\n";

    private const string ARRAY_ONE = "[1]\n";

    private const string INT_TAG = '!!int';

    private const string FLOAT_TAG = '!!float';

    #[DataProvider('errorCases')]
    public function testErrorMessages(string $json, string $expectedMessage): void
    {
        try {
            $reader = new JsonReader($json);
            while ($reader->next() instanceof \LTS\PhpXq\Yaml\Node) {
                // drain every value so a later malformed one is reached
            }
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
        yield 'open object' => ['{', self::END];

        yield 'object ends after key' => ['{"a"', self::END];

        yield 'object ends after colon' => ['{"a":', self::END];

        yield 'object ends after comma' => ['{"a":1,', self::END];

        yield 'key without colon' => ['{"a" 1}', "json: invalid character '1' after object key"];

        yield 'unquoted key' => ['{a:1}', "json: invalid character 'a' looking for beginning of object key string"];

        yield 'trailing comma in object' => ['{"a":1,}', "json: invalid character '}' looking for beginning of object key string"];

        yield 'missing comma in object' => ['{"a":1 "b":2}', "json: invalid character '\"' after object key:value pair"];

        yield 'missing comma in array' => ['[1 2]', "json: invalid character '2' after array element"];

        yield 'open array' => ['[', self::END];

        yield 'array ends after comma' => ['[1,', self::END];

        yield 'array ends after element' => ['[1', self::END];

        yield 'trailing comma in array' => ['[1,]', \sprintf(self::BEGINNING_OF_VALUE, ']')];

        yield 'lone minus' => ['-', self::END];

        yield 'minus then letter' => ['-a', "json: invalid character 'a' in numeric literal"];

        yield 'leading zero' => ['01', \sprintf(self::AFTER_TOP_LEVEL, '1')];

        yield 'negative leading zero' => ['-01', \sprintf(self::AFTER_TOP_LEVEL, '1')];

        yield 'double zero' => ['00', \sprintf(self::AFTER_TOP_LEVEL, '0')];

        yield 'dot at the end' => ['1.', self::END];

        yield 'dot then letter' => ['1.x', "json: invalid character 'x' after decimal point in numeric literal"];

        yield 'exponent at the end' => ['1e', self::END];

        yield 'exponent sign at the end' => ['1e+', self::END];

        yield 'exponent minus at the end' => ['1e-', self::END];

        yield 'exponent then letter' => ['1ex', "json: invalid character 'x' in exponent of numeric literal"];

        yield 'exponent sign then letter' => ['1e+x', "json: invalid character 'x' in exponent of numeric literal"];

        yield 'number then letter' => ['1x', \sprintf(self::BEGINNING_OF_VALUE, 'x')];

        yield 'two dots' => ['1.5.5', \sprintf(self::AFTER_TOP_LEVEL, '.')];

        yield 'number then plus' => ['1+', \sprintf(self::AFTER_TOP_LEVEL, '+')];

        yield 'number then minus' => ['1-', \sprintf(self::AFTER_TOP_LEVEL, '-')];

        yield 'number then exponent letter after a fraction' => ['1.5e', self::END];

        yield 'huge number' => ['1e400', 'json: cannot unmarshal number 1e400 into Go value of type float64'];

        yield 'huge negative number' => ['-1e400', 'json: cannot unmarshal number -1e400 into Go value of type float64'];

        yield 'unterminated string' => ['"abc', self::END];

        yield 'string ends after backslash' => ['"a\\', self::END];

        yield 'bad escape' => ['"a\q"', "json: invalid character 'q' in string escape code"];

        yield 'short unicode escape' => ['"a\u12"', "json: invalid character '1' in \\u hexadecimal character escape"];

        yield 'non-hex unicode escape' => ['"a\u12zz"', "json: invalid character '1' in \\u hexadecimal character escape"];

        yield 'surrogate followed by an empty escape' => ['"\ud83d\u"', "json: invalid character '\"' in \\u hexadecimal character escape"];

        yield 'surrogate followed by a short escape' => ['"\ud83d\ude0"', "json: invalid character 'd' in \\u hexadecimal character escape"];

        yield 'newline in string' => ["\"a\nb\"", "json: invalid character '\n' in string literal"];

        yield 'control character in string' => ["\"a\x01\"", "json: invalid character '\x01' in string literal"];

        yield 'unit separator in string' => ["\"a\x1f\"", "json: invalid character '\x1f' in string literal"];

        yield 'truncated true' => ['tru', \sprintf(self::BEGINNING_OF_VALUE, 't')];

        yield 'truncated null' => ['nul', \sprintf(self::BEGINNING_OF_VALUE, 'n')];

        yield 'word with a suffix' => ['falsey', \sprintf(self::BEGINNING_OF_VALUE, 'y')];

        yield 'bare letter' => ['x', \sprintf(self::BEGINNING_OF_VALUE, 'x')];

        yield 'closing brace' => ['}', \sprintf(self::BEGINNING_OF_VALUE, '}')];

        yield 'garbage after a value' => ['[1] x', \sprintf(self::BEGINNING_OF_VALUE, 'x')];

        yield 'garbage after a string' => ['"abc"x', \sprintf(self::BEGINNING_OF_VALUE, 'x')];
    }

    #[DataProvider('numberCases')]
    public function testNumbers(string $json, string $expectedTag, string $expectedText): void
    {
        $node = new JsonReader($json)->next();

        self::assertNotNull($node);
        self::assertSame($expectedTag, $node->tag);
        self::assertSame($expectedText, $node->value);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function numberCases(): iterable
    {
        yield 'zero' => ['0', self::INT_TAG, '0'];

        yield 'negative zero' => ['-0', self::INT_TAG, '0'];

        yield 'nine' => ['9', self::INT_TAG, '9'];

        yield 'minus nine' => ['-9', self::INT_TAG, '-9'];

        yield 'negative one' => ['-1', self::INT_TAG, '-1'];

        yield 'upper case exponent' => ['1E5', self::INT_TAG, self::HUNDRED_THOUSAND];

        yield 'exponent with plus' => ['1e+5', self::INT_TAG, self::HUNDRED_THOUSAND];

        yield 'upper case exponent with plus' => ['1E+5', self::INT_TAG, self::HUNDRED_THOUSAND];

        yield 'exponent with minus' => ['1e-5', self::FLOAT_TAG, self::SMALL_EXPONENT];

        yield 'upper case exponent with minus' => ['1E-5', self::FLOAT_TAG, self::SMALL_EXPONENT];

        yield 'fraction and exponent' => ['1.5e3', self::INT_TAG, '1500'];

        yield 'eighteen digits' => ['123456789012345678', self::INT_TAG, '123456789012345678'];

        yield 'nineteen digits in range' => ['1234567890123456789', self::INT_TAG, '1234567890123456789'];

        yield 'largest int' => ['9223372036854775807', self::INT_TAG, '9223372036854775807'];

        yield 'smallest int' => ['-9223372036854775808', self::INT_TAG, '-9223372036854775808'];

        yield 'one past the largest int' => ['9223372036854775808', self::FLOAT_TAG, '9.223372036854776e+18'];

        yield 'one past the smallest int' => ['-9223372036854775809', self::FLOAT_TAG, '-9.223372036854776e+18'];

        yield 'twenty digits' => ['12345678901234567890', self::FLOAT_TAG, '1.2345678901234567e+19'];

        yield 'huge integer' => ['100000000000000000000000', self::FLOAT_TAG, '1e+23'];

        yield 'one tenth' => ['0.1', self::FLOAT_TAG, '0.1'];

        yield 'smallest plain decimal' => ['0.0001', self::FLOAT_TAG, '0.0001'];

        yield 'below the plain decimal range' => ['0.00001', self::FLOAT_TAG, self::SMALL_EXPONENT];

        yield 'largest plain decimal' => ['123456.5', self::FLOAT_TAG, '123456.5'];

        yield 'above the plain decimal range' => ['1234567.5', self::FLOAT_TAG, '1.2345675e+06'];

        yield 'one million' => ['1e6', self::FLOAT_TAG, '1e+06'];

        yield 'one hundred thousand' => ['1e5', self::INT_TAG, self::HUNDRED_THOUSAND];

        yield 'big exponent' => ['1.5e300', self::FLOAT_TAG, '1.5e+300'];

        yield 'small exponent' => ['1.5e-300', self::FLOAT_TAG, '1.5e-300'];

        yield 'negative small exponent' => ['-1.5e-7', self::FLOAT_TAG, '-1.5e-07'];

        yield 'negative fraction' => ['-0.5', self::FLOAT_TAG, '-0.5'];

        yield 'negative big exponent' => ['-1.5e7', self::FLOAT_TAG, '-1.5e+07'];

        yield 'plain fraction' => ['123.456', self::FLOAT_TAG, '123.456'];

        yield 'e21' => ['1e21', self::FLOAT_TAG, '1e+21'];

        yield 'whole float' => ['1.0', self::INT_TAG, '1'];

        yield 'whole float with trailing zero' => ['10.0', self::INT_TAG, '10'];

        yield 'fraction after whole digits' => ['120.5', self::FLOAT_TAG, '120.5'];
    }

    #[DataProvider('stringCases')]
    public function testStrings(string $json, string $expected): void
    {
        $node = new JsonReader($json)->next();

        self::assertNotNull($node);
        self::assertSame($expected, $node->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stringCases(): iterable
    {
        yield 'surrogate pair' => ['"\ud83d\ude00"', "\u{1F600}"];

        yield 'high surrogate then a plain escape' => ['"\ud83d\u0041"', "\u{FFFD}A"];

        yield 'lone high surrogate' => ['"\ud83d"', "\u{FFFD}"];

        yield 'high surrogate then text' => ['"\ud83dx"', "\u{FFFD}x"];

        yield 'lone low surrogate' => ['"\ude00"', "\u{FFFD}"];

        yield 'highest high surrogate with the lowest low surrogate' => ['"\udbff\udc00"', "\u{10FC00}"];

        yield 'two high surrogates' => ['"\udbff\udbff"', "\u{FFFD}\u{FFFD}"];

        yield 'highest low surrogate' => ['"\ud800\udfff"', "\u{103FF}"];

        yield 'lowest pair' => ['"\ud800\udc00"', "\u{10000}"];

        yield 'below the surrogates' => ['"\ud7ff\udc00"', "\u{D7FF}\u{FFFD}"];

        yield 'high surrogate twice then a low surrogate' => ['"\ud83d\ud83d\ude00"', "\u{FFFD}\u{1F600}"];

        yield 'two plain escapes' => ['"\u00e9\u0041"', "\u{E9}A"];

        yield 'escaped slash' => ['"a\/b"', 'a/b'];

        yield 'backspace and form feed' => ['"a\bb\fb"', "a\x08b\x0cb"];

        yield 'every simple escape' => ['"\"\\\\\/\b\f\n\r\t"', "\"\\/\x08\x0c\n\r\t"];

        yield 'plain text then an escape' => ['"abc\ndef"', "abc\ndef"];

        yield 'empty string' => ['""', ''];

        yield 'escape right after the opening quote' => ['"\n"', "\n"];
    }

    #[DataProvider('structureCases')]
    public function testStructures(string $json, string $expected): void
    {
        $reader  = new JsonReader($json);
        $encoder = new JsonEncoder();
        $out     = '';
        while (($node = $reader->next()) instanceof \LTS\PhpXq\Yaml\Node) {
            $out .= $encoder->encode($node, new FormatOptions(indent: 0, unwrapScalar: false), 0);
        }

        self::assertSame($expected, $out);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function structureCases(): iterable
    {
        yield 'spaces everywhere in an object' => ["{ \"a\" : 1 , \"b\" :\n2 }", "{\"a\":1,\"b\":2}\n"];

        yield 'spaces in an array' => ['[ 1 , 2 ]', "[1,2]\n"];

        yield 'empty object with a space' => ['{ }', "{}\n"];

        yield 'empty array with a space' => ['[ ]', "[]\n"];

        yield 'nested empty object' => ['{"a":{}}', "{\"a\":{}}\n"];

        yield 'digits as array items' => ['[9,0]', "[9,0]\n"];

        yield 'negative digits as array items' => ['[-9,-0]', "[-9,0]\n"];

        yield 'object holding an array holding an object' => ['{"a":[1,{"b":null}]}', "{\"a\":[1,{\"b\":null}]}\n"];

        yield 'value after a space before the closing brace' => ['{"a":1 }', self::OBJECT_A];

        yield 'value after a space before the closing bracket' => ['[1 ]', self::ARRAY_ONE];

        yield 'space after the opening bracket' => ['[ 1]', self::ARRAY_ONE];

        yield 'space between key and colon' => ['{"a" :1}', self::OBJECT_A];

        yield 'space after the opening brace' => ['{ "a":1}', self::OBJECT_A];

        yield 'concatenated values' => ["{\"a\":1} 2\n[3]", "{\"a\":1}\n2\n[3]\n"];

        yield 'empty input' => ['', ''];

        yield 'blank input' => [" \t\r\n", ''];

        yield 'byte order mark' => ["\u{FEFF}[1]", self::ARRAY_ONE];

        yield 'literals' => ['[true,false,null]', "[true,false,null]\n"];
    }
}
