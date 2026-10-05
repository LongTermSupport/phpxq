<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use Fiber;
use LTS\PhpXq\Jq\Runtime\Eval\EvaluationStack;
use LTS\PhpXq\Jq\Runtime\Eval\RunState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(EvaluationStack::class)]
final class EvaluationStackTest extends TestCase
{
    public function testTheBodyRunsInsideAFiber(): void
    {
        $inFiber = false;

        EvaluationStack::run(static function () use (&$inFiber): void {
            $inFiber = Fiber::getCurrent() instanceof Fiber;
        });

        self::assertTrue($inFiber);
    }

    public function testANestedRunReusesTheCurrentFiber(): void
    {
        $same = false;

        EvaluationStack::run(static function () use (&$same): void {
            $outer = Fiber::getCurrent();
            EvaluationStack::run(static function () use ($outer, &$same): void {
                $same = Fiber::getCurrent() === $outer;
            });
        });

        self::assertTrue($same);
    }

    public function testExceptionsPropagateToTheCaller(): void
    {
        try {
            EvaluationStack::run(static function (): void {
                throw new RuntimeException('from the body');
            });
            self::fail('the exception was swallowed');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('from the body', $runtimeException->getMessage());
        }
    }

    public function testTheProcessStackSettingIsRestored(): void
    {
        $before = ini_get('fiber.stack_size');

        EvaluationStack::run(static function (): void {
        });

        self::assertSame($before, ini_get('fiber.stack_size'));
    }

    public function testTheStackHoldsTheWholeCallBudgetOfNativeRecursion(): void
    {
        $reached = 0;
        $descend = static function (int $level) use (&$descend, &$reached): void {
            $reached = $level;
            if ($level < RunState::MAX_CALL_DEPTH) {
                $descend($level + 1);
            }
        };

        EvaluationStack::run(static function () use ($descend): void {
            $descend(1);
        });

        self::assertSame(RunState::MAX_CALL_DEPTH, $reached);
    }
}
