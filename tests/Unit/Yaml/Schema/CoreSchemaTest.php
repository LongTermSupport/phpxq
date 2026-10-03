<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Schema;

use LTS\PhpXq\Yaml\Schema\CoreSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CoreSchemaTest extends TestCase
{
    #[DataProvider('provideResolutions')]
    public function testResolve(string $plain, string $expectedTag): void
    {
        self::assertSame($expectedTag, CoreSchema::resolve($plain));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideResolutions(): iterable
    {
        yield 'empty is null' => ['', CoreSchema::TAG_NULL];
        yield 'tilde is null' => ['~', CoreSchema::TAG_NULL];
        yield 'null' => ['null', CoreSchema::TAG_NULL];
        yield 'NULL' => ['NULL', CoreSchema::TAG_NULL];
        yield 'true' => ['true', CoreSchema::TAG_BOOL];
        yield 'False' => ['False', CoreSchema::TAG_BOOL];
        yield 'yes is a string in 1.2' => ['yes', CoreSchema::TAG_STR];
        yield 'int' => ['123', CoreSchema::TAG_INT];
        yield 'negative int' => ['-7', CoreSchema::TAG_INT];
        yield 'hex' => ['0x1F', CoreSchema::TAG_INT];
        yield 'octal' => ['0o17', CoreSchema::TAG_INT];
        yield 'float' => ['1.5', CoreSchema::TAG_FLOAT];
        yield 'float exponent' => ['1e3', CoreSchema::TAG_FLOAT];
        yield 'leading dot float' => ['.5', CoreSchema::TAG_FLOAT];
        yield 'infinity' => ['-.inf', CoreSchema::TAG_FLOAT];
        yield 'nan' => ['.nan', CoreSchema::TAG_FLOAT];
        yield 'word' => ['hello', CoreSchema::TAG_STR];
        yield 'version-like' => ['1.2.3', CoreSchema::TAG_STR];
    }
}
