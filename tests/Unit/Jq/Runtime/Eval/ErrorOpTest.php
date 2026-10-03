<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ErrorOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ErrorOp::class)]
final class ErrorOpTest extends OpTestCase
{
    public function testWithoutAMessageTheInputIsTheErrorValue(): void
    {
        try {
            self::outputs(new ErrorOp(null), self::object(['a' => 1]));
            self::fail('expected an error');
        } catch (JqException $exception) {
            self::assertEquals(self::object(['a' => 1]), $exception->value);
        }
    }

    public function testTheMessageOutputIsTheErrorValue(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('boom');

        self::outputs(new ErrorOp(self::constant('boom')), 1);
    }

    public function testNullIsAValidErrorValue(): void
    {
        try {
            self::outputs(new ErrorOp(self::constant(null)));
            self::fail('expected an error');
        } catch (JqException $exception) {
            self::assertNull($exception->value);
        }
    }

    public function testAnEmptyMessageRaisesNothing(): void
    {
        self::assertSame([], self::outputs(new ErrorOp(self::generator([]))));
    }

    public function testPathModeRaisesToo(): void
    {
        $this->expectException(JqException::class);

        self::pathOutputs(new ErrorOp(null), 'x');
    }
}
