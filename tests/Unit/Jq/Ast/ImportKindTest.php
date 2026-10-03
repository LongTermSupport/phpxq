<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\ImportKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ImportKindTest extends TestCase
{
    #[DataProvider('provideKinds')]
    public function testResolvesFromItsValue(string $value, ImportKind $expected): void
    {
        self::assertSame($expected, ImportKind::from($value));
    }

    /**
     * @return iterable<string, array{string, ImportKind}>
     */
    public static function provideKinds(): iterable
    {
        yield 'import' => ['import', ImportKind::Import];
        yield 'include' => ['include', ImportKind::Include];
        yield 'data' => ['data', ImportKind::Data];
    }
}
