<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Cli;

use LTS\PhpXq\Yq\Cli\FormatDetector;
use LTS\PhpXq\Yq\Format\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FormatDetectorTest extends TestCase
{
    #[DataProvider('filenameProvider')]
    public function testFromFilename(string $filename, Format $expected): void
    {
        self::assertSame($expected, new FormatDetector()->fromFilename($filename));
    }

    /**
     * @return iterable<string, array{string, Format}>
     */
    public static function filenameProvider(): iterable
    {
        yield 'yml'        => ['a.yml', Format::Yaml];
        yield 'yaml'       => ['dir/a.yaml', Format::Yaml];
        yield 'tfstate'    => ['a.tfstate', Format::Yaml];
        yield 'no ext'     => ['Makefile', Format::Yaml];
        yield 'json'       => ['a.json', Format::Json];
        yield 'upper'      => ['A.JSON', Format::Json];
        yield 'properties' => ['a.properties', Format::Props];
        yield 'csv'        => ['a.csv', Format::Csv];
        yield 'tsv'        => ['a.tsv', Format::Tsv];
        yield 'xml'        => ['a.xml', Format::Xml];
        yield 'toml'       => ['a.toml', Format::Toml];
        yield 'tf'         => ['a.tf', Format::Hcl];
        yield 'hcl'        => ['a.hcl', Format::Hcl];
    }
}
