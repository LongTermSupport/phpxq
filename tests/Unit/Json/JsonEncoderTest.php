<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use InvalidArgumentException;
use LTS\PhpXq\Json\ColorScheme;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
final class JsonEncoderTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function compactProvider(): iterable
    {
        yield 'null'                 => [null, 'null'];
        yield 'true'                 => [true, 'true'];
        yield 'false'                => [false, 'false'];
        yield 'int'                  => [42, '42'];
        yield 'negative int'         => [-7, '-7'];
        yield 'zero'                 => [0, '0'];
        yield 'float'                => [1.5, '1.5'];
        yield 'integral float'       => [3.0, '3'];
        yield 'negative zero'        => [-0.0, '-0'];
        yield 'small float'          => [0.00001, '1e-05'];
        yield 'tiny'                 => [1.5e-10, '1.5e-10'];
        yield 'plain small'          => [0.0001, '0.0001'];
        yield 'one e17'              => [1e17, '1e+17'];
        yield 'big float'            => [1.5e300, '1.5e+300'];
        yield 'seventeen digits'     => [0.1 + 0.2, '0.30000000000000004'];
        yield 'nan'                  => [\NAN, 'null'];
        yield 'infinity'             => [\INF, '1.7976931348623157e+308'];
        yield 'negative infinity'    => [-\INF, '-1.7976931348623157e+308'];
        yield 'precise'              => [new PreciseNumber(1.0, '1.000'), '1.000'];
        yield 'precise big'          => [new PreciseNumber(1.0e22, '12345678909876543212345'), '12345678909876543212345'];
        yield 'precise overflow'     => [new PreciseNumber(\INF, '1E+1000'), '1E+1000'];
        yield 'string'               => ['abc', '"abc"'];
        yield 'empty string'         => ['', '""'];
        yield 'empty array'          => [[], '[]'];
        yield 'empty object'         => [new JsonObject(), '{}'];
        yield 'array'                => [[1, 'a', null, [true]], '[1,"a",null,[true]]'];
        yield 'object'               => [JsonObject::fromPairs(['b' => 1, 'a' => [2]]), '{"b":1,"a":[2]}'];
        yield 'numeric keys'         => [JsonObject::fromPairs([1 => 'x', '01' => 'y']), '{"1":"x","01":"y"}'];
        yield 'empty key'            => [JsonObject::fromPairs(['' => 1]), '{"":1}'];
        yield 'quote and backslash'  => ["a\"b\\c", '"a\\"b\\\\c"'];
        yield 'solidus untouched'    => ['a/b', '"a/b"'];
        yield 'short escapes'        => ["\x08\x0c\n\r\t", '"\\b\\f\\n\\r\\t"'];
        yield 'control characters'   => ["\x00\x01\x1f", '"\\u0000\\u0001\\u001f"'];
        yield 'delete'               => ["\x7f", '"\\u007f"'];
        yield 'printable edge'       => [' ~', '" ~"'];
        yield 'non ascii'            => ["\u{e9}\u{20ac}\u{1f600}", "\"\u{e9}\u{20ac}\u{1f600}\""];
        yield 'line separators'      => ["\u{2028}\u{2029}", "\"\u{2028}\u{2029}\""];
        yield 'invalid utf8'         => ["a\xffb", "\"a\u{fffd}b\""];
        yield 'truncated utf8'       => ["a\xe2\x82", "\"a\u{fffd}\""];
        yield 'invalid among escapes' => ["\n\xff\"", "\"\\n\u{fffd}\\\"\""];
        yield 'keys are escaped'     => [JsonObject::fromPairs(["a\"b" => 1, "\u{e9}" => 2]), "{\"a\\\"b\":1,\"\u{e9}\":2}"];
    }

    #[DataProvider('compactProvider')]
    public function testCompact(mixed $value, string $expected): void
    {
        self::assertSame($expected, (new JsonEncoder())->encode($value, EncodeOptions::compact()));
    }

    public function testPrettyPrintsTwoSpacesByDefault(): void
    {
        $value = JsonObject::fromPairs(['a' => [1, 2, JsonObject::fromPairs(['b' => null])], 'c' => new JsonObject(), 'd' => []]);

        $expected = <<<'JSON'
            {
              "a": [
                1,
                2,
                {
                  "b": null
                }
              ],
              "c": {},
              "d": []
            }
            JSON;

        self::assertSame($expected, (new JsonEncoder())->encode($value, new EncodeOptions()));
    }

    public function testIndentWidth(): void
    {
        $encoder = new JsonEncoder();

        self::assertSame("[\n    1,\n    [\n        2\n    ]\n]", $encoder->encode([1, [2]], new EncodeOptions(indent: 4)));
        self::assertSame("[\n 1\n]", $encoder->encode([1], new EncodeOptions(indent: 1)));
        self::assertSame('[1,[2]]', $encoder->encode([1, [2]], new EncodeOptions(indent: 0)));
    }

    public function testTabIndent(): void
    {
        $encoder = new JsonEncoder();

        self::assertSame("{\n\t\"a\": [\n\t\t1\n\t]\n}", $encoder->encode(JsonObject::fromPairs(['a' => [1]]), new EncodeOptions(useTab: true)));
        self::assertSame("[\n\t1\n]", $encoder->encode([1], new EncodeOptions(indent: 0, useTab: true)));
    }

    public function testScalarsHaveNoIndentation(): void
    {
        self::assertSame('1', (new JsonEncoder())->encode(1, new EncodeOptions()));
        self::assertSame('"x"', (new JsonEncoder())->encode('x', new EncodeOptions()));
    }

    public function testSortKeysRecursivelyByCodepoint(): void
    {
        $value = JsonObject::fromPairs([
            'b'        => 1,
            'a'        => JsonObject::fromPairs(['z' => 1, 'y' => [JsonObject::fromPairs(['d' => 1, 'c' => 2])]]),
            "\u{e9}"   => 3,
            'B'        => 4,
            '10'       => 5,
            '9'        => 6,
        ]);

        $json = (new JsonEncoder())->encode($value, new EncodeOptions(indent: 0, sortKeys: true));

        self::assertSame("{\"10\":5,\"9\":6,\"B\":4,\"a\":{\"y\":[{\"c\":2,\"d\":1}],\"z\":1},\"b\":1,\"\u{e9}\":3}", $json);
    }

    public function testKeysKeepInsertionOrderWithoutSorting(): void
    {
        $value = JsonObject::fromPairs(['b' => 1, 'a' => 2, '2' => 3, '1' => 4]);

        self::assertSame('{"b":1,"a":2,"2":3,"1":4}', (new JsonEncoder())->encode($value, EncodeOptions::compact()));
    }

    public function testAsciiOutputEscapesNonAscii(): void
    {
        $options = new EncodeOptions(indent: 0, ascii: true);
        $encoder = new JsonEncoder();

        self::assertSame('"\\u00e9\\u20ac\\ud83d\\ude00"', $encoder->encode("\u{e9}\u{20ac}\u{1f600}", $options));
        self::assertSame('"a\\u007f\\n"', $encoder->encode("a\x7f\n", $options));
        self::assertSame('"\\ufffd"', $encoder->encode("\xff", $options));
        self::assertSame('{"\\u00e9":"\\u00e9"}', $encoder->encode(JsonObject::fromPairs(["\u{e9}" => "\u{e9}"]), $options));
        self::assertSame('"plain"', $encoder->encode('plain', $options));
    }

    public function testRejectsValuesOutsideTheModel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JsonEncoder())->encode(new stdClass(), EncodeOptions::compact());
    }

    public function testRoundTripOfDecodedDocument(): void
    {
        $text = '{"a":[1,1.5,"x\\ny",null,true,{"b":{}}],"big":12345678909876543212345,"d":1.000,"e":0.00001}';

        $decoded = (new JsonDecoder())->decodeOne($text);

        self::assertSame($text, (new JsonEncoder())->encode($decoded, EncodeOptions::compact()));
    }

    public function testDefaultColoursMatchJq(): void
    {
        $value = [JsonObject::fromPairs(['a' => true, 'b' => false]), 'abc', 123, null];
        $json  = (new JsonEncoder())->encode($value, new EncodeOptions(indent: 0, colors: ColorScheme::default()));

        $expected = "\e[1;39m[\e[0m"
            . "\e[1;39m{\e[0m"
            . "\e[1;34m\"a\"\e[0m"
            . "\e[1;39m:\e[0m"
            . "\e[0;39mtrue\e[0m"
            . "\e[1;39m,\e[0m"
            . "\e[1;34m\"b\"\e[0m"
            . "\e[1;39m:\e[0m"
            . "\e[0;39mfalse\e[0m"
            . "\e[1;39m}\e[0m"
            . "\e[1;39m,\e[0m"
            . "\e[0;32m\"abc\"\e[0m"
            . "\e[1;39m,\e[0m"
            . "\e[0;39m123\e[0m"
            . "\e[1;39m,\e[0m"
            . "\e[0;90mnull\e[0m"
            . "\e[1;39m]\e[0m";

        self::assertSame($expected, $json);
    }

    public function testPrettyColours(): void
    {
        $value = [JsonObject::fromPairs(['a' => true, 'b' => false]), 'abc', 123, null];
        $json  = (new JsonEncoder())->encode($value, new EncodeOptions(colors: ColorScheme::default()));

        $expected = "\e[1;39m[\e[0m\n"
            . "  \e[1;39m{\e[0m\n"
            . "    \e[1;34m\"a\"\e[0m"
            . "\e[1;39m:\e[0m "
            . "\e[0;39mtrue\e[0m"
            . "\e[1;39m,\e[0m\n"
            . "    \e[1;34m\"b\"\e[0m"
            . "\e[1;39m:\e[0m "
            . "\e[0;39mfalse\e[0m\n"
            . "  \e[1;39m}\e[0m"
            . "\e[1;39m,\e[0m\n"
            . "  \e[0;32m\"abc\"\e[0m"
            . "\e[1;39m,\e[0m\n"
            . "  \e[0;39m123\e[0m"
            . "\e[1;39m,\e[0m\n"
            . "  \e[0;90mnull\e[0m\n"
            . "\e[1;39m]\e[0m";

        self::assertSame($expected, $json);
    }

    public function testCustomColoursAndEmptyContainers(): void
    {
        $colors = new ColorScheme("\e[0;30m", "\e[0;31m", "\e[0;32m", "\e[0;33m", "\e[0;34m", "\e[1;35m", "\e[1;36m", "\e[1;37m");
        $json   = (new JsonEncoder())->encode([JsonObject::fromPairs(['a' => true]), [], new JsonObject(), 1.5], new EncodeOptions(indent: 0, colors: $colors));

        $expected = "\e[1;35m[\e[0m"
            . "\e[1;36m{\e[0m\e[1;37m\"a\"\e[0m\e[1;36m:\e[0m\e[0;32mtrue\e[0m\e[1;36m}\e[0m"
            . "\e[1;35m,\e[0m"
            . "\e[1;35m[]\e[0m"
            . "\e[1;35m,\e[0m"
            . "\e[1;36m{}\e[0m"
            . "\e[1;35m,\e[0m"
            . "\e[0;33m1.5\e[0m"
            . "\e[1;35m]\e[0m";

        self::assertSame($expected, $json);
    }

    public function testColouredSortedAndAsciiOutput(): void
    {
        $colors = new ColorScheme('', '', '', '', '[S', '[A', '[O', '[K');
        $json   = (new JsonEncoder())->encode(JsonObject::fromPairs(['b' => "\u{e9}", 'a' => 1]), new EncodeOptions(indent: 0, sortKeys: true, ascii: true, colors: $colors));

        self::assertSame("[O{\e[0m[K\"a\"\e[0m[O:\e[0m1\e[0m[O,\e[0m[K\"b\"\e[0m[O:\e[0m[S\"\\u00e9\"\e[0m[O}\e[0m", $json);
    }

    public function testColouredScalarsUseTheirOwnColour(): void
    {
        $options = new EncodeOptions(colors: ColorScheme::default());
        $encoder = new JsonEncoder();

        self::assertSame("\e[0;90mnull\e[0m", $encoder->encode(null, $options));
        self::assertSame("\e[0;39mfalse\e[0m", $encoder->encode(false, $options));
        self::assertSame("\e[0;39m1.000\e[0m", $encoder->encode(new PreciseNumber(1.0, '1.000'), $options));
        self::assertSame("\e[0;39m-0\e[0m", $encoder->encode(-0.0, $options));
    }
}
