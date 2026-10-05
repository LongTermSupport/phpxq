<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ErrorOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ErrorOp::class)]
final class ErrorOpTest extends OpTestCase
{
    use AssertsRaised;

    public function testWithoutAMessageTheInputIsTheErrorValue(): void
    {
        try {
            self::outputs(new ErrorOp(null), self::object(['a' => 1]));
            self::fail('expected an error');
        } catch (JqException $jqException) {
            self::assertEquals(self::object(['a' => 1]), $jqException->value);
        }
    }

    public function testTheMessageOutputIsTheErrorValue(): void
    {
        self::assertRaises(JqException::class, 'boom', static fn (): mixed => self::outputs(new ErrorOp(self::constant('boom')), 1));
    }

    public function testNullIsAValidErrorValue(): void
    {
        try {
            self::outputs(new ErrorOp(self::constant(null)));
            self::fail('expected an error');
        } catch (JqException $jqException) {
            self::assertNull($jqException->value);
        }
    }

    public function testAnEmptyMessageRaisesNothing(): void
    {
        self::assertSame([], self::outputs(new ErrorOp(self::generator())));
    }

    public function testPathModeRaisesToo(): void
    {
        $this->expectException(JqException::class);

        self::pathOutputs(new ErrorOp(null), 'x');
    }
}
