<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\ImportKindEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ImportKindTest extends TestCase
{
    #[DataProvider('provideKinds')]
    public function testResolvesFromItsValue(string $value, ImportKindEnum $expected): void
    {
        self::assertSame($expected, ImportKindEnum::from($value));
    }

    /**
     * @return iterable<string, array{string, ImportKindEnum}>
     */
    public static function provideKinds(): iterable
    {
        yield 'import' => ['import', ImportKindEnum::Import];
        yield 'include' => ['include', ImportKindEnum::Include];
        yield 'data' => ['data', ImportKindEnum::Data];
    }
}
