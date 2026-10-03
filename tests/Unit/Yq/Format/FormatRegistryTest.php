<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format;

use LTS\PhpXq\Yq\Format\FormatEnum;
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
    public function testEveryFormatHasAnEncoder(FormatEnum $format): void
    {
        $registry = new FormatRegistry();

        self::assertSame($format, $registry->encoder($format)->format());
        self::assertSame($registry->encoder($format), $registry->encoder($format));
    }

    #[DataProvider('everyFormat')]
    public function testReadableFormatsHaveADecoder(FormatEnum $format): void
    {
        $registry = new FormatRegistry();
        if (FormatEnum::Shell === $format || FormatEnum::Kyaml === $format) {
            $this->expectException(FormatException::class);
        }

        self::assertSame($format, $registry->decoder($format)->format());
    }

    /**
     * @return iterable<string, array{FormatEnum}>
     */
    public static function everyFormat(): iterable
    {
        foreach (FormatEnum::cases() as $format) {
            yield $format->value => [$format];
        }
    }

    public function testLuaIsReadableEvenThoughTheFormatEnumSaysOtherwise(): void
    {
        self::assertFalse(FormatEnum::Lua->canDecode());
        self::assertSame(FormatEnum::Lua, new FormatRegistry()->decoder(FormatEnum::Lua)->format());
    }

    #[DataProvider('filenames')]
    public function testFormatFromFilename(string $filename, ?FormatEnum $expected): void
    {
        self::assertSame($expected, FormatRegistry::fromFilename($filename));
    }

    /**
     * @return iterable<string, array{string, ?FormatEnum}>
     */
    public static function filenames(): iterable
    {
        yield 'yaml' => ['a.yaml', FormatEnum::Yaml];

        yield 'yml' => ['dir/a.YML', FormatEnum::Yaml];

        yield 'json' => ['a.json', FormatEnum::Json];

        yield 'xml' => ['a.xml', FormatEnum::Xml];

        yield 'properties' => ['a.properties', FormatEnum::Props];

        yield 'props' => ['a.props', FormatEnum::Props];

        yield 'csv' => ['a.csv', FormatEnum::Csv];

        yield 'tsv' => ['a.tsv', FormatEnum::Tsv];

        yield 'toml' => ['a.toml', FormatEnum::Toml];

        yield 'hcl' => ['main.tf', FormatEnum::Hcl];

        yield 'tfvars' => ['a.tfvars', FormatEnum::Hcl];

        yield 'lua' => ['a.lua', FormatEnum::Lua];

        yield 'unknown extension' => ['a.txt', null];

        yield 'no extension' => ['Makefile', null];
    }
}
