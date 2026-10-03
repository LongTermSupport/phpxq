<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format;

use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FormatRegistryTest extends TestCase
{
    #[DataProvider('everyFormat')]
    public function testEveryFormatHasAnEncoder(Format $format): void
    {
        $registry = new FormatRegistry();

        self::assertSame($format, $registry->encoder($format)->format());
        self::assertSame($registry->encoder($format), $registry->encoder($format));
    }

    #[DataProvider('everyFormat')]
    public function testReadableFormatsHaveADecoder(Format $format): void
    {
        $registry = new FormatRegistry();
        if (Format::Shell === $format || Format::Kyaml === $format) {
            $this->expectException(FormatException::class);
        }

        self::assertSame($format, $registry->decoder($format)->format());
    }

    /**
     * @return iterable<string, array{Format}>
     */
    public static function everyFormat(): iterable
    {
        foreach (Format::cases() as $format) {
            yield $format->value => [$format];
        }
    }

    public function testLuaIsReadableEvenThoughTheFormatEnumSaysOtherwise(): void
    {
        self::assertFalse(Format::Lua->canDecode());
        self::assertSame(Format::Lua, new FormatRegistry()->decoder(Format::Lua)->format());
    }

    #[DataProvider('filenames')]
    public function testFormatFromFilename(string $filename, ?Format $expected): void
    {
        self::assertSame($expected, FormatRegistry::fromFilename($filename));
    }

    /**
     * @return iterable<string, array{string, ?Format}>
     */
    public static function filenames(): iterable
    {
        yield 'yaml' => ['a.yaml', Format::Yaml];

        yield 'yml' => ['dir/a.YML', Format::Yaml];

        yield 'json' => ['a.json', Format::Json];

        yield 'xml' => ['a.xml', Format::Xml];

        yield 'properties' => ['a.properties', Format::Props];

        yield 'props' => ['a.props', Format::Props];

        yield 'csv' => ['a.csv', Format::Csv];

        yield 'tsv' => ['a.tsv', Format::Tsv];

        yield 'toml' => ['a.toml', Format::Toml];

        yield 'hcl' => ['main.tf', Format::Hcl];

        yield 'tfvars' => ['a.tfvars', Format::Hcl];

        yield 'lua' => ['a.lua', Format::Lua];

        yield 'unknown extension' => ['a.txt', null];

        yield 'no extension' => ['Makefile', null];
    }
}
