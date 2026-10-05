<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\FormatDetector;
use LTS\PhpXq\Yq\Format\FormatEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FormatDetectorTest extends TestCase
{
    #[DataProvider('filenameProvider')]
    public function testFromFilename(string $filename, FormatEnum $expected): void
    {
        self::assertSame($expected, new FormatDetector()->fromFilename($filename));
    }

    /**
     * @return iterable<string, array{string, FormatEnum}>
     */
    public static function filenameProvider(): iterable
    {
        yield 'yml'        => ['a.yml', FormatEnum::Yaml];
        yield 'yaml'       => ['dir/a.yaml', FormatEnum::Yaml];
        yield 'tfstate'    => ['a.tfstate', FormatEnum::Yaml];
        yield 'no ext'     => ['Makefile', FormatEnum::Yaml];
        yield 'json'       => ['a.json', FormatEnum::Json];
        yield 'upper'      => ['A.JSON', FormatEnum::Json];
        yield 'properties' => ['a.properties', FormatEnum::Props];
        yield 'csv'        => ['a.csv', FormatEnum::Csv];
        yield 'tsv'        => ['a.tsv', FormatEnum::Tsv];
        yield 'xml'        => ['a.xml', FormatEnum::Xml];
        yield 'toml'       => ['a.toml', FormatEnum::Toml];
        yield 'tf'         => ['a.tf', FormatEnum::Hcl];
        yield 'hcl'        => ['a.hcl', FormatEnum::Hcl];
        yield 'props'      => ['a.props', FormatEnum::Props];
        yield 'lua'        => ['a.lua', FormatEnum::Lua];
        yield 'bare word'  => ['json', FormatEnum::Yaml];
        yield 'dot at end' => ['a.', FormatEnum::Yaml];
        yield 'last ext'   => ['a.json.bak', FormatEnum::Yaml];
    }
}
