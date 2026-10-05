<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yq\Format\Codec\StringFormats;
use LTS\PhpXq\Yq\Format\FormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class StringFormatsTest extends TestCase
{
    public function testBase64RoundTrip(): void
    {
        self::assertSame('YSBzcGVjaWFsIHN0cmluZw==', StringFormats::base64Encode('a special string'));
        self::assertSame('a special string', StringFormats::base64Decode("\n YSBzcGVjaWFsIHN0cmluZw==  \n\n"));
        self::assertSame('a special string', StringFormats::base64Decode('YSBzcGVjaWFsIHN0cmluZw'));
    }

    public function testBase64DecodeRejectsGarbage(): void
    {
        $this->expectException(FormatException::class);
        StringFormats::base64Decode('!!!');
    }

    public function testBase64UrlUsesUrlSafeAlphabet(): void
    {
        self::assertSame('Pj4tPz8_', StringFormats::base64UrlEncode('>>-???'));
        self::assertSame('>>-???', StringFormats::base64UrlDecode('Pj4tPz8_'));
        self::assertSame('Works with UTF-16 😊', StringFormats::base64UrlDecode('V29ya3Mgd2l0aCBVVEYtMTYg8J-Yig=='));
        self::assertSame('V29ya3Mgd2l0aCBVVEYtMTYg8J-Yig==', StringFormats::base64UrlEncode('Works with UTF-16 😊'));
    }

    public function testUriEncodeFollowsQueryEscape(): void
    {
        self::assertSame('this+has+%26+special+%28%29+characters+%2A', StringFormats::uriEncode('this has & special () characters *'));
        self::assertSame('a-b_c.d~e', StringFormats::uriEncode('a-b_c.d~e'));
    }

    public function testUriDecode(): void
    {
        self::assertSame('this has & special () characters *', StringFormats::uriDecode('this+has+%26+special+%28%29+characters+%2A'));
    }

    public function testUriDecodeRejectsBadEscape(): void
    {
        $this->expectException(FormatException::class);
        StringFormats::uriDecode('100%');
    }


    #[DataProvider('shellQuoteCases')]
    public function testShellQuote(string $input, string $expected): void
    {
        self::assertSame($expected, StringFormats::shellQuote($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shellQuoteCases(): iterable
    {
        yield 'safe' => ['turquoise', 'turquoise'];

        yield 'space' => ['Mike Wazowski', "'Mike Wazowski'"];

        yield 'quote' => ["Miles O'Brien", "'Miles O'\"'\"'Brien'"];

        yield 'empty' => ['', "''"];
    }
}
