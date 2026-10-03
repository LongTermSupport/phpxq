<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\SingleStringInterpOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(SingleStringInterpOp::class)]
final class SingleStringInterpOpTest extends OpTestCase
{
    public function testSplicesTheFormattedValues(): void
    {
        $op = new SingleStringInterpOp(
            ['x=', new FieldOp('x'), ', y=', new FieldOp('y')],
            static fn (mixed $value): string => '[' . (\is_int($value) ? $value : 0) . ']',
        );

        self::assertSame(['x=[1], y=[2]'], self::outputs($op, self::object(['x' => 1, 'y' => 2])));
    }

    public function testPlainTextOnly(): void
    {
        $op = new SingleStringInterpOp(['abc'], static fn (): string => 'unused');

        self::assertSame('abc', $op->value(null, null));
    }
}
