<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\NegateOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(NegateOp::class)]
final class NegateOpTest extends OpTestCase
{
    use AssertsRaised;

    public function testNegatesEveryOutput(): void
    {
        self::assertSame([-1, 2], self::outputs(new NegateOp(self::generator(1, -2))));
    }

    public function testRejectsNonNumbers(): void
    {
        self::assertRaises(JqException::class, 'string ("a") cannot be negated', static fn (): mixed => self::outputs(new NegateOp(self::generator('a'))));
    }
}
