<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\AltOp;
use LTS\PhpXq\Jq\Runtime\Eval\ErrorOp;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(AltOp::class)]
final class AltOpTest extends OpTestCase
{
    use AssertsRaised;

    public function testEmitsEveryTruthyLeftOutput(): void
    {
        $op = new AltOp(self::generator([false, 1, null, 2]), self::constant(9));

        self::assertSame([1, 2], self::outputs($op));
    }

    public function testFallsBackWhenNothingIsTruthy(): void
    {
        self::assertSame([9], self::outputs(new AltOp(self::generator([false, null]), self::constant(9))));
        self::assertSame([9], self::outputs(new AltOp(self::generator([]), self::constant(9))));
    }

    public function testErrorsInTheLeftOperandAreSwallowed(): void
    {
        $op = new AltOp(new ErrorOp(self::constant('x')), self::constant(9));

        self::assertSame([9], self::outputs($op));
    }

    public function testAnErrorAfterATruthyOutputEndsTheLeftOperandWithoutFallback(): void
    {
        $op = new AltOp($this->generatorThenError(), self::constant(9));

        self::assertSame([1], self::outputs($op));
    }

    public function testErrorsFromTheContinuationPropagate(): void
    {
        self::assertRaises(JqException::class, 'downstream', static function (): void {
            new AltOp(self::constant(1), self::constant(2))->run(null, null, static function (): never {
                throw new JqException('downstream');
            });
        });
    }

    public function testPathModeKeepsThePathsOfTruthyValues(): void
    {
        $op    = new AltOp(new FieldOp('a'), new FieldOp('b'));
        $input = self::object(['a' => null, 'b' => 2]);

        self::assertSame([[['b'], 2]], self::pathOutputs($op, $input));
        self::assertSame([[['a'], 1]], self::pathOutputs($op, self::object(['a' => 1, 'b' => 2])));
    }

    private function generatorThenError(): \LTS\PhpXq\Jq\Runtime\Eval\OpInterface
    {
        return new \LTS\PhpXq\Jq\Runtime\Eval\CommaOp(self::constant(1), new ErrorOp(self::constant('late')));
    }
}
