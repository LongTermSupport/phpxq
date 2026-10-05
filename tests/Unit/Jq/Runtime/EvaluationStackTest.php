<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use Fiber;
use LTS\PhpXq\Jq\Runtime\EvaluationStack;
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
        self::assertTrue(EvaluationStack::run(static fn (): bool => Fiber::getCurrent() instanceof Fiber));
    }

    public function testTheBodyResultIsReturned(): void
    {
        self::assertSame(42, EvaluationStack::run(static fn (): int => 42));
    }

    public function testANestedRunReusesTheCurrentFiber(): void
    {
        $same = EvaluationStack::run(static function (): bool {
            $outer = Fiber::getCurrent();

            return EvaluationStack::run(static fn (): bool => Fiber::getCurrent() === $outer);
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
            if ($level < EvaluationStack::MAX_CALL_DEPTH) {
                $descend($level + 1);
            }
        };

        EvaluationStack::run(static function () use ($descend): void {
            $descend(1);
        });

        self::assertSame(EvaluationStack::MAX_CALL_DEPTH, $reached);
    }
}
