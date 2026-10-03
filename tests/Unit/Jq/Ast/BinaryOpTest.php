<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\BinaryOpEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BinaryOpTest extends TestCase
{
    #[DataProvider('provideSpellings')]
    public function testValuesAreSourceSpellings(string $spelling, BinaryOpEnum $expected): void
    {
        self::assertSame($expected, BinaryOpEnum::from($spelling));
    }

    /**
     * @return iterable<string, array{string, BinaryOpEnum}>
     */
    public static function provideSpellings(): iterable
    {
        yield 'alternative' => ['//', BinaryOpEnum::Alt];
        yield 'and' => ['and', BinaryOpEnum::And];
        yield 'not equal' => ['!=', BinaryOpEnum::Neq];
    }
}
