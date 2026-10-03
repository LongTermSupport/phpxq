<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\BreakException;
use LTS\PhpXq\Jq\Runtime\Eval\CommaOp;
use LTS\PhpXq\Jq\Runtime\Eval\ErrorOp;
use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\TryOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

#[CoversClass(TryOp::class)]
final class TryOpTest extends OpTestCase
{
    public function testWithoutHandlerAnErrorEndsTheOutput(): void
    {
        $op = new TryOp(new CommaOp(self::constant(1), new ErrorOp(self::constant('x'))), null);

        self::assertSame([1], self::outputs($op));
    }

    public function testHandlerReceivesTheErrorValue(): void
    {
        $op = new TryOp(new ErrorOp(self::constant('boom')), new IdentityOp());

        self::assertSame(['boom'], self::outputs($op));
    }

    public function testErrorNullIsCaught(): void
    {
        $op = new TryOp(new ErrorOp(self::constant(null)), self::constant('caught'));

        self::assertSame(['caught'], self::outputs($op));
    }

    public function testErrorsRaisedByTheContinuationAreNotTheBodysErrors(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('downstream');

        new TryOp(self::constant(1), self::constant('handler'))->run(null, null, static function (): void {
            throw new JqException('downstream');
        });
    }

    public function testBodyErrorsAfterAnEmissionAreStillCaught(): void
    {
        $seen = [];
        new TryOp(new CommaOp(self::constant(1), new ErrorOp(self::constant('late'))), self::constant('handled'))
            ->run(null, null, static function (mixed $value) use (&$seen): void {
                $seen[] = $value;
            });

        self::assertSame([1, 'handled'], $seen);
    }

    public function testBreakIsNotAnError(): void
    {
        $label = new stdClass();
        $break = new class($label) extends \LTS\PhpXq\Jq\Runtime\Eval\AbstractOp {
            public function __construct(private readonly object $label)
            {
            }

            public function run(?\LTS\PhpXq\Jq\Runtime\Eval\Env $env, mixed $input, \Closure $emit): void
            {
                throw new BreakException($this->label);
            }
        };

        $this->expectException(BreakException::class);

        self::outputs(new TryOp($break, self::constant('handler')));
    }

    public function testPathModeCatchesAndRunsTheHandlerAsAValue(): void
    {
        $op = new TryOp(new FieldOp('a'), self::constant('h'));

        self::assertSame([[['a'], 1]], self::pathOutputs($op, self::object(['a' => 1])));
        self::assertSame([[null, 'h']], self::pathOutputs($op, 5));
    }

    public function testPathModeWithoutHandlerSwallowsTheError(): void
    {
        self::assertSame([], self::pathOutputs(new TryOp(new FieldOp('a'), null), 5));
    }

    public function testPathModeContinuationErrorsPropagate(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('downstream');

        new TryOp(new IdentityOp(), null)->paths(null, [], 1, static function (): void {
            throw new JqException('downstream');
        });
    }
}
