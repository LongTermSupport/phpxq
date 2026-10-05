<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\BreakException;
use LTS\PhpXq\Jq\Runtime\Eval\BreakOp;
use LTS\PhpXq\Jq\Runtime\Eval\CommaOp;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\LabelOp;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(LabelOp::class)]
final class LabelOpTest extends OpTestCase
{
    public function testBreakEndsTheBodysOutput(): void
    {
        $op = new LabelOp(new CommaOp(self::constant(1), new CommaOp(new BreakOp(0), self::constant(2))));

        self::assertSame([1], self::outputs($op));
    }

    public function testNormalCompletionIsUntouched(): void
    {
        self::assertSame([1, 2], self::outputs(new LabelOp(self::generator(1, 2))));
    }

    public function testAForeignBreakPropagates(): void
    {
        $foreign = new stdClass();
        $env     = new Env(null, $foreign);

        $this->expectException(BreakException::class);

        // depth 1 from inside the label is the entry the test pushed, not this label's own token
        self::outputs(new LabelOp(new BreakOp(1)), null, $env);
    }

    public function testAnOuterLabelCatchesABreakFromAnInnerLabelsBody(): void
    {
        $outer = new LabelOp(new CommaOp(self::constant('before'), new LabelOp(new CommaOp(new BreakOp(1), self::constant('never')))));

        self::assertSame(['before'], self::outputs($outer));
    }

    public function testPathModeAlsoCatchesBreak(): void
    {
        $op = new LabelOp(new CommaOp(new IterateOp(null), new BreakOp(0)));

        self::assertSame([[[0], 'a']], self::pathOutputs($op, ['a']));
    }

    public function testEachActivationHasItsOwnToken(): void
    {
        $op = new LabelOp(new CommaOp(self::generator(1), new BreakOp(0)));

        self::assertSame([1], self::outputs($op));
        self::assertSame([1], self::outputs($op));
    }
}
