<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\FormatFunctions;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FormatFunctionsTest extends TestCase
{
    private const string SPECIAL = "!()<>&'\"\t";

    #[DataProvider('specialCharacters')]
    public function testFormats(string $format, string $expected): void
    {
        self::assertSame($expected, Harness::call('format', self::SPECIAL, [$format]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function specialCharacters(): iterable
    {
        yield 'text' => ['text', self::SPECIAL];
        yield 'json' => ['json', '"!()<>&\'\"\t"'];
        yield 'html' => ['html', "!()&lt;&gt;&amp;&apos;&quot;\t"];
        yield 'uri' => ['uri', '%21%28%29%3C%3E%26%27%22%09'];
        yield 'sh' => ['sh', "'!()<>&'\\''\"\t'"];
        yield 'base64' => ['base64', 'ISgpPD4mJyIJ'];
    }

    public function testTextFormatsNonStringsAsJson(): void
    {
        self::assertSame('[1,"a"]', Harness::call('format', [1, 'a'], ['text']));
        self::assertSame('[&quot;&lt;&quot;,[1]]', Harness::call('format', ['<', [1]], ['html']));
    }

    public function testCsv(): void
    {
        self::assertSame("1,\"!()<>&'\"\"\t\"", Harness::call('format', Harness::json('[1,"!()<>&\'\"\t"]'), ['csv']));
        self::assertSame('1,,true,false,"x"', Harness::call('format', Harness::json('[1,null,true,false,"x"]'), ['csv']));
        self::assertSame('1.5,10.0', Harness::call('format', Harness::json('[1.5,10.0]'), ['csv']));
        self::assertSame('', Harness::call('format', [], ['csv']));
    }

    public function testCsvValidation(): void
    {
        self::assertSame('string ("a") cannot be csv-formatted, only an array can be', Harness::error('format', 'a', ['csv']));
        self::assertSame('array ([1]) is not valid in a csv row', Harness::error('format', [[1]], ['csv']));
    }

    public function testTsv(): void
    {
        self::assertSame("1\t!()<>&'\"\\t", Harness::call('format', Harness::json('[1,"!()<>&\'\"\t"]'), ['tsv']));
        self::assertSame("a\\nb\\rc\\\\d\t", Harness::call('format', Harness::json('["a\nb\rc\\\d",null]'), ['tsv']));
        self::assertSame("true\tfalse", Harness::call('format', [true, false], ['tsv']));
    }

    public function testTsvValidation(): void
    {
        self::assertSame('number (1) cannot be tsv-formatted, only an array can be', Harness::error('format', 1, ['tsv']));
        self::assertSame('object ({}) is not valid in a tsv row', Harness::error('format', [Harness::json('{}')], ['tsv']));
    }

    public function testHtmlEscapesEverySpecial(): void
    {
        self::assertSame('&lt;script&gt;hax&lt;/script&gt;', Harness::call('format', '<script>hax</script>', ['html']));
        self::assertSame('Tom &amp; Jerry', Harness::call('format', 'Tom & Jerry', ['html']));
        self::assertSame('1 &lt; 2', Harness::call('format', '1 < 2', ['html']));
    }

    public function testUri(): void
    {
        self::assertSame('%CE%BC', Harness::call('format', "\u{3bc}", ['uri']));
        self::assertSame('a%20%CE%BC%20%E2%88%B0%20%F0%9F%98%8E', Harness::call('format', "a \u{3bc} \u{2230} \u{1f60e}", ['uri']));
        self::assertSame('-_.~AZaz09', Harness::call('format', '-_.~AZaz09', ['uri']));
        self::assertSame('a%00b', Harness::call('format', "a\u{0}b", ['uri']));
        self::assertSame('%5B1%5D', Harness::call('format', [1], ['uri']));
    }

    public function testUriDecode(): void
    {
        self::assertSame("\u{3bc}", Harness::call('format', '%CE%BC', ['urid']));
        self::assertSame("\u{e4}b\u{e7}d\u{eb}", Harness::call('format', '%c3%a4b%c3%a7d%c3%ab', ['urid']));
        self::assertSame('hello world', Harness::call('format', 'hello world', ['urid']));
        self::assertSame("a\u{0}b", Harness::call('format', 'a%00b', ['urid']));
        self::assertSame('a+b', Harness::call('format', 'a+b', ['urid']));
    }

    #[DataProvider('invalidUris')]
    public function testUriDecodeRejects(string $text): void
    {
        self::assertSame(
            \sprintf('string (%s) is not a valid uri encoding', json_encode($text, \JSON_THROW_ON_ERROR)),
            Harness::error('format', $text, ['urid']),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUris(): iterable
    {
        yield 'percent at the end' => ['abc%'];
        yield 'one hex digit' => ['abc%f'];
        yield 'not hex' => ['abc%g'];
        yield 'invalid hex value' => ['%FX%9F%98%8E'];
        yield 'incomplete sequence' => ['%F0%93%81'];
        yield 'bad continuation' => ['%F0%C0%81%8E'];
    }

    public function testShell(): void
    {
        self::assertSame("'O'\\''Hara'\\''s Ale'", Harness::call('format', "O'Hara's Ale", ['sh']));
        self::assertSame("'a' 1 null true 'b c'", Harness::call('format', Harness::json('["a",1,null,true,"b c"]'), ['sh']));
        self::assertSame('1.5', Harness::call('format', 1.5, ['sh']));
        self::assertSame('array ([1]) can not be escaped for shell', Harness::error('format', [[1]], ['sh']));
        self::assertSame('object ({}) can not be escaped for shell', Harness::error('format', [Harness::json('{}')], ['sh']));
    }

    public function testBase64(): void
    {
        self::assertSame('', Harness::call('format', '', ['base64']));
        self::assertSame('Zm/Ds2Jhcgo=', Harness::call('format', "fo\u{f3}bar\n", ['base64']));
        self::assertSame('PD4mJyIJ', Harness::call('format', "<>&'\"\t", ['base64']));
    }

    public function testBase64Decode(): void
    {
        self::assertSame('', Harness::call('format', '', ['base64d']));
        self::assertSame('', Harness::call('format', '=', ['base64d']));
        self::assertSame("fo\u{f3}bar\n", Harness::call('format', 'Zm/Ds2Jhcgo=', ['base64d']));
        self::assertSame("qixbaz\n", Harness::call('format', 'cWl4YmF6Cg', ['base64d']));
        self::assertSame('This is a message', Harness::call('format', 'VGhpcyBpcyBhIG1lc3NhZ2U=', ['base64d']));
    }

    public function testBase64DecodeReplacesInvalidUtf8(): void
    {
        self::assertSame("\u{fffd}", Harness::call('format', '/w==', ['base64d']));
    }

    public function testBase64DecodeValidation(): void
    {
        self::assertSame('string ("Not base64 data") is not valid base64 data', Harness::error('format', 'Not base64 data', ['base64d']));
        self::assertSame('string ("QUJDa") trailing base64 byte found', Harness::error('format', 'QUJDa', ['base64d']));
    }

    public function testBase32(): void
    {
        self::assertSame('', Harness::call('format', '', ['base32']));
        self::assertSame('MY======', Harness::call('format', 'f', ['base32']));
        self::assertSame('MZXQ====', Harness::call('format', 'fo', ['base32']));
        self::assertSame('MZXW6===', Harness::call('format', 'foo', ['base32']));
        self::assertSame('MZXW6YQ=', Harness::call('format', 'foob', ['base32']));
        self::assertSame('MZXW6YTB', Harness::call('format', 'fooba', ['base32']));
        self::assertSame('MZXW6YTBOI======', Harness::call('format', 'foobar', ['base32']));
    }

    public function testBase32Decode(): void
    {
        self::assertSame('foobar', Harness::call('format', 'MZXW6YTBOI======', ['base32d']));
        self::assertSame('foobar', Harness::call('format', 'MZXW6YTBOI', ['base32d']));
        self::assertSame('f', Harness::call('format', 'MY======', ['base32d']));
        self::assertSame('', Harness::call('format', '', ['base32d']));
        self::assertSame('string ("m") is not valid base32 data', Harness::error('format', 'm', ['base32d']));
    }

    public function testUnknownFormat(): void
    {
        self::assertSame('xml is not a valid format', Harness::error('format', 'a', ['xml']));
        self::assertSame('number (1) is not a valid format', Harness::error('format', 'a', [1]));
    }

    public function testApplyIsPublic(): void
    {
        self::assertSame('a%20b', FormatFunctions::apply('uri', 'a b'));
    }
}
