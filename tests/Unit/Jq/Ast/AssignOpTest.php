<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\AssignOp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AssignOpTest extends TestCase
{
    #[DataProvider('provideSpellings')]
    public function testValuesAreSourceSpellings(string $spelling, AssignOp $expected): void
    {
        self::assertSame($expected, AssignOp::from($spelling));
    }

    /**
     * @return iterable<string, array{string, AssignOp}>
     */
    public static function provideSpellings(): iterable
    {
        yield 'set' => ['=', AssignOp::Set];
        yield 'update' => ['|=', AssignOp::Update];
        yield 'alternative' => ['//=', AssignOp::Alt];
    }
}
