<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\NegateOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(NegateOp::class)]
final class NegateOpTest extends OpTestCase
{
    public function testNegatesEveryOutput(): void
    {
        self::assertSame([-1, 2], self::outputs(new NegateOp(self::generator([1, -2]))));
    }

    public function testRejectsNonNumbers(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('string ("a") cannot be negated');

        self::outputs(new NegateOp(self::generator(['a'])));
    }
}
