<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ReservedGlobalEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReservedGlobalEnum::class)]
final class ReservedGlobalEnumTest extends TestCase
{
    #[DataProvider('names')]
    public function testTheTwoReservedGlobalsResolveByName(string $name, ?ReservedGlobalEnum $expected): void
    {
        self::assertSame($expected, ReservedGlobalEnum::tryFrom($name));
    }

    /**
     * @return iterable<string, array{string, ?ReservedGlobalEnum}>
     */
    public static function names(): iterable
    {
        yield 'ENV' => ['ENV', ReservedGlobalEnum::Env];

        yield '__prog_args' => ['__prog_args', ReservedGlobalEnum::ProgArgs];

        yield 'names are case sensitive' => ['env', null];
    }
}
