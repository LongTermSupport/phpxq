<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Ast;

use LTS\PhpXq\Jq\Ast\AssignOpEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AssignOpTest extends TestCase
{
    #[DataProvider('provideSpellings')]
    public function testValuesAreSourceSpellings(string $spelling, AssignOpEnum $expected): void
    {
        self::assertSame($expected, AssignOpEnum::from($spelling));
    }

    /**
     * @return iterable<string, array{string, AssignOpEnum}>
     */
    public static function provideSpellings(): iterable
    {
        yield 'set' => ['=', AssignOpEnum::Set];
        yield 'update' => ['|=', AssignOpEnum::Update];
        yield 'alternative' => ['//=', AssignOpEnum::Alt];
    }
}
