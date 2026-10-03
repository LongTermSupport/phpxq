<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Parser\TokenTypeEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TokenTypeTest extends TestCase
{
    public function testValuesAreUnique(): void
    {
        $values = array_map(static fn (TokenTypeEnum $type): string => $type->value, TokenTypeEnum::cases());

        self::assertSame($values, array_values(array_unique($values)));
    }

    #[DataProvider('provideKeywords')]
    public function testKeywordsResolveFromTheirSpelling(string $spelling, TokenTypeEnum $expected): void
    {
        self::assertSame($expected, TokenTypeEnum::from($spelling));
    }

    /**
     * @return iterable<string, array{string, TokenTypeEnum}>
     */
    public static function provideKeywords(): iterable
    {
        yield 'reduce' => ['reduce', TokenTypeEnum::KwReduce];
        yield 'foreach' => ['foreach', TokenTypeEnum::KwForeach];
    }
}
