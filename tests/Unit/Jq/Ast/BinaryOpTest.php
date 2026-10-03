<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\BinaryOp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BinaryOpTest extends TestCase
{
    #[DataProvider('provideSpellings')]
    public function testValuesAreSourceSpellings(string $spelling, BinaryOp $expected): void
    {
        self::assertSame($expected, BinaryOp::from($spelling));
    }

    /**
     * @return iterable<string, array{string, BinaryOp}>
     */
    public static function provideSpellings(): iterable
    {
        yield 'alternative' => ['//', BinaryOp::Alt];
        yield 'and' => ['and', BinaryOp::And];
        yield 'not equal' => ['!=', BinaryOp::Neq];
    }
}
