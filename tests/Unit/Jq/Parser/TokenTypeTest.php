<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Parser\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TokenTypeTest extends TestCase
{
    public function testValuesAreUnique(): void
    {
        $values = array_map(static fn (TokenType $type): string => $type->value, TokenType::cases());

        self::assertSame($values, array_values(array_unique($values)));
    }

    #[DataProvider('provideKeywords')]
    public function testKeywordsResolveFromTheirSpelling(string $spelling, TokenType $expected): void
    {
        self::assertSame($expected, TokenType::from($spelling));
    }

    /**
     * @return iterable<string, array{string, TokenType}>
     */
    public static function provideKeywords(): iterable
    {
        yield 'reduce' => ['reduce', TokenType::KwReduce];
        yield 'foreach' => ['foreach', TokenType::KwForeach];
    }
}
