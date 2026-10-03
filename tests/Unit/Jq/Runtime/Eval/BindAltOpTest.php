<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ArrayBinder;
use LTS\PhpXq\Jq\Runtime\Eval\ArrayOp;
use LTS\PhpXq\Jq\Runtime\Eval\BindAltOp;
use LTS\PhpXq\Jq\Runtime\Eval\CommaOp;
use LTS\PhpXq\Jq\Runtime\Eval\ErrorOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\ObjectBinder;
use LTS\PhpXq\Jq\Runtime\Eval\Op;
use LTS\PhpXq\Jq\Runtime\Eval\VarBinder;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(BindAltOp::class)]
final class BindAltOpTest extends OpTestCase
{
    public function testFallsThroughToTheNextAlternativeOnADestructuringError(): void
    {
        // `.[] as {a: $x} ?// $x | $x`
        $op = new BindAltOp(
            new IterateOp(null),
            [new ObjectBinder([['x', null, null]]), new VarBinder()],
            [['x'], ['x']],
            ['x'],
            new VarOp(0),
        );

        self::assertEquals([1, [2]], self::outputs($op, [self::object(['x' => 1]), [2]]));
    }

    public function testVariablesOfOtherAlternativesAreNull(): void
    {
        // `. as [$a] ?// $b | [$a, $b]`
        $op = new BindAltOp(
            self::identity(),
            [new ArrayBinder([new VarBinder()]), new VarBinder()],
            [['a'], ['b']],
            ['a', 'b'],
            self::pair(),
        );

        self::assertSame([[1, null]], self::outputs($op, [1]));
        self::assertSame([[null, 5]], self::outputs($op, 5));
    }

    public function testAnErrorInTheBodyMovesToTheNextAlternative(): void
    {
        $op = new BindAltOp(
            self::identity(),
            [new VarBinder(), new VarBinder()],
            [['a'], ['a']],
            ['a'],
            new ErrorOp(self::constant('boom')),
        );

        $this->expectException(JqException::class);
        $this->expectExceptionMessage('boom');

        self::outputs($op, 1);
    }

    public function testTheLastAlternativesErrorPropagates(): void
    {
        $op = new BindAltOp(
            self::identity(),
            [new ArrayBinder([new VarBinder()])],
            [['a']],
            ['a'],
            new VarOp(0),
        );

        $this->expectException(JqException::class);

        self::outputs($op, self::object(['k' => 1]));
    }

    public function testErrorsFromTheContinuationDoNotSelectAnotherAlternative(): void
    {
        $op    = new BindAltOp(self::identity(), [new VarBinder(), new VarBinder()], [['a'], ['a']], ['a'], new VarOp(0));
        $calls = 0;

        try {
            $op->run(null, 1, static function () use (&$calls): void {
                ++$calls;

                throw new JqException('downstream');
            });
            self::fail('expected an exception');
        } catch (JqException $exception) {
            self::assertSame('downstream', $exception->getMessage());
            self::assertSame(1, $calls);
        }
    }

    public function testPathModeKeepsTheBodyInPathMode(): void
    {
        $op = new BindAltOp(
            self::identity(),
            [new VarBinder(), new VarBinder()],
            [['a'], ['a']],
            ['a'],
            new IterateOp(null),
        );

        self::assertSame([[[0], 'x']], self::pathOutputs($op, ['x']));
    }

    private static function pair(): Op
    {
        return new ArrayOp(new CommaOp(new VarOp(1), new VarOp(0)));
    }
}
