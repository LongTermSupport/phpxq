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
final class FormatRegistryLookupTest extends TestCase
{
    #[DataProvider('extensionCases')]
    public function testFromFilename(string $filename, ?FormatEnum $expected): void
    {
        self::assertSame($expected, FormatRegistry::fromFilename($filename));
    }

    /**
     * @return iterable<string, array{string, ?FormatEnum}>
     */
    public static function extensionCases(): iterable
    {
        yield 'hcl' => ['main.hcl', FormatEnum::Hcl];

        yield 'tf' => ['main.tf', FormatEnum::Hcl];

        yield 'tfvars' => ['vars.tfvars', FormatEnum::Hcl];

        yield 'upper case hcl' => ['MAIN.HCL', FormatEnum::Hcl];

        yield 'props' => ['a.props', FormatEnum::Props];

        yield 'lua' => ['a.lua', FormatEnum::Lua];

        yield 'tsv' => ['a.tsv', FormatEnum::Tsv];

        yield 'name that ends like an extension but has no dot' => ['xyaml', null];

        yield 'name made only of an extension word' => ['json', null];

        yield 'unknown extension' => ['a.unknown', null];

        yield 'dot at the end' => ['a.', null];

        yield 'only the last extension counts' => ['a.json.bak', null];

        yield 'directory with a dot' => ['dir.yaml/file', null];
    }

    #[DataProvider('everyFormat')]
    public function testEncodersAreBuiltOnceAndReused(FormatEnum $format): void
    {
        $registry = new FormatRegistry();

        self::assertSame($registry->encoder($format), $registry->encoder($format));
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

    public function testDecodersAreBuiltOnceAndReused(): void
    {
        $registry = new FormatRegistry();

        self::assertSame($registry->decoder(FormatEnum::Json), $registry->decoder(FormatEnum::Json));
        self::assertNotSame($registry->decoder(FormatEnum::Json), $registry->decoder(FormatEnum::Yaml));
    }

    public function testShellCannotBeRead(): void
    {
        try {
            new FormatRegistry()->decoder(FormatEnum::Shell);
        } catch (FormatException $exception) {
            self::assertSame('cannot read shell input; it is an output only format', $exception->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }
}
