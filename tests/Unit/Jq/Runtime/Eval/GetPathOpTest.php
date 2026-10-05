<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\GetPathOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(GetPathOp::class)]
final class GetPathOpTest extends OpTestCase
{
    use AssertsRaised;

    public function testReadsTheValueAtThePath(): void
    {
        $input = self::object(['a' => [10, 20]]);

        self::assertSame([20], self::outputs(new GetPathOp(self::constant(['a', 1])), $input));
        self::assertSame([null], self::outputs(new GetPathOp(self::constant(['x', 1])), $input));
    }

    public function testEveryPathOutputIsLookedUp(): void
    {
        $op = new GetPathOp(self::generator([0], [1]));

        self::assertSame([5, 6], self::outputs($op, [5, 6]));
    }

    public function testRejectsAPathThatIsNotAnArray(): void
    {
        self::assertRaises(JqException::class, 'Path must be specified as an array', static fn (): mixed => self::outputs(new GetPathOp(self::constant('a')), 1));
    }

    public function testTypeErrorsOnTheWayPropagate(): void
    {
        self::assertRaises(JqException::class, 'Cannot index number with string ("b")', static fn (): mixed => self::outputs(new GetPathOp(self::constant(['a', 'b'])), self::object(['a' => 1])));
    }

    public function testPathModeExtendsTheCurrentPath(): void
    {
        $op = new GetPathOp(self::constant(['a', 0]));

        self::assertSame([[['p', 'a', 0], 7]], self::pathOutputs($op, self::object(['a' => [7]]), ['p']));
    }

    public function testPathModeWithoutAPathReportsAComputedValue(): void
    {
        $op = new GetPathOp(self::constant(['a']));

        self::assertSame([[null, 7]], self::pathOutputs($op, self::object(['a' => 7]), null));
    }

    public function testPathModeRejectsAPathThatIsNotAnArray(): void
    {
        $this->expectException(JqException::class);

        self::pathOutputs(new GetPathOp(self::constant(1)));
    }
}
