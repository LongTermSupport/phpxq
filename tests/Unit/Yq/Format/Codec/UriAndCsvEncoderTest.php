<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\Codec\CsvEncoder;
use LTS\PhpXq\Yq\Format\Codec\StringFormats;
use LTS\PhpXq\Yq\Format\Codec\UriEncoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class UriAndCsvEncoderTest extends TestCase
{
    private const string STANDARD_ALPHABET = '+/8=';

    #[DataProvider('rejectedCases')]
    public function testEncodersRejectWhatTheyCannotWrite(callable $encode, string $expectedMessage): void
    {
        try {
            $encode();
        } catch (FormatException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function rejectedCases(): iterable
    {
        yield 'uri of a map' => [
            static fn (): string => new UriEncoder()->encode(Node::mapping(), new FormatOptions(), 0),
            'cannot encode !!map as uri, can only operate on scalars',
        ];

        yield 'uri of a sequence' => [
            static fn (): string => new UriEncoder()->encode(Node::sequence(), new FormatOptions(), 0),
            'cannot encode !!seq as uri, can only operate on scalars',
        ];

        yield 'csv of a map' => [
            static fn (): string => new CsvEncoder(FormatEnum::Csv)->encode(Node::mapping(), new FormatOptions(), 0),
            'csv: only arrays can be written as csv, got a map',
        ];

        yield 'tsv of a map' => [
            static fn (): string => new CsvEncoder(FormatEnum::Tsv)->encode(Node::mapping(), new FormatOptions(), 0),
            'csv: only arrays can be written as tsv, got a map',
        ];

        yield 'uri decode of a bad escape' => [
            static fn (): string => StringFormats::uriDecode('a%zzb'),
            'invalid URL escape in a%zzb',
        ];

        yield 'uri decode of a truncated escape' => [
            static fn (): string => StringFormats::uriDecode('50%'),
            'invalid URL escape in 50%',
        ];

        yield 'standard base64 does not accept the url alphabet' => [
            static fn (): string => StringFormats::base64Decode('-_8='),
            'illegal base64 data',
        ];
    }

    public function testUrlAlphabetIsTranslatedOnlyForTheUrlVariant(): void
    {
        self::assertSame("\xfb\xff", StringFormats::base64UrlDecode('-_8='));
        self::assertSame("\xfb\xff", StringFormats::base64Decode(self::STANDARD_ALPHABET));
        self::assertSame("\xfb\xff", StringFormats::base64UrlDecode('-_8'));
        self::assertSame("\xfb\xff", StringFormats::base64UrlDecode(self::STANDARD_ALPHABET));
    }

    public function testAFieldStartingWithAUnicodeSpaceIsQuoted(): void
    {
        $sequence = Node::sequence([Node::sequence([Node::scalar('h')]), Node::sequence([Node::scalar("\u{A0}x")]), Node::sequence([Node::scalar("\u{2003}y")])]);

        self::assertSame("h\n\"\u{A0}x\"\n\"\u{2003}y\"\n", new CsvEncoder(FormatEnum::Csv)->encode($sequence, new FormatOptions(), 0));
    }

    public function testAFieldStartingWithAnAsciiSpaceIsQuoted(): void
    {
        $sequence = Node::sequence([Node::sequence([Node::scalar('h')]), Node::sequence([Node::scalar(' x')]), Node::sequence([Node::scalar("\ty")])]);

        self::assertSame("h\n\" x\"\n\"\ty\"\n", new CsvEncoder(FormatEnum::Csv)->encode($sequence, new FormatOptions(), 0));
    }
}
