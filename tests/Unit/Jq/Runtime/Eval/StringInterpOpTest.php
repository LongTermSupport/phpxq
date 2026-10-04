<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\StringInterpOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(StringInterpOp::class)]
final class StringInterpOpTest extends OpTestCase
{
    public function testLastInterpolationIsTheOuterLoop(): void
    {
        $op = new StringInterpOp(
            [self::generator(1, 2), ' ', self::generator(3, 4)],
            static fn (mixed $value): string => \is_int($value) ? (string)$value : '',
        );

        self::assertSame(['1 3', '2 3', '1 4', '2 4'], self::outputs($op));
    }

    public function testFormatsOnlyTheInterpolatedValues(): void
    {
        $op = new StringInterpOp(['<', self::generator('a'), '>'], static fn (mixed $value): string => strtoupper(\is_string($value) ? $value : ''));

        self::assertSame(['<A>'], self::outputs($op));
    }

    public function testEmptyInterpolationYieldsNothing(): void
    {
        $op = new StringInterpOp(['x', self::generator()], static fn (): string => '');

        self::assertSame([], self::outputs($op));
    }

    public function testPathModeReportsAComputedValue(): void
    {
        $op = new StringInterpOp(['a', self::generator(1)], static fn (mixed $value): string => \is_int($value) ? (string)$value : '');

        self::assertSame([[null, 'a1']], self::pathOutputs($op));
    }
}
