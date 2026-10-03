<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\EarlyExit;
use LTS\PhpXq\Jq\Runtime\BreakException;
use LTS\PhpXq\Jq\Runtime\JqException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
final class EarlyExitTest extends TestCase
{
    public function testABodyThatFinishesReportsNoExit(): void
    {
        $ran = false;

        self::assertFalse(EarlyExit::run(static function (object $label) use (&$ran): void {
            $ran = true;
        }));
        self::assertTrue($ran);
    }

    public function testStopEndsTheBodyAndIsReported(): void
    {
        self::assertTrue(EarlyExit::run(static function (object $label): never {
            EarlyExit::stop($label);
        }));
    }

    public function testAForeignBreakPassesThrough(): void
    {
        $foreign = new stdClass();

        try {
            EarlyExit::run(static function () use ($foreign): never {
                EarlyExit::stop($foreign);
            });
            self::fail('the foreign break was swallowed');
        } catch (BreakException $breakException) {
            self::assertSame($foreign, $breakException->label);
        }
    }

    public function testNestedExitsEndOnlyTheirOwnBody(): void
    {
        $inner = null;
        $outer = EarlyExit::run(static function (object $outerLabel) use (&$inner): void {
            $inner = EarlyExit::run(static function (object $innerLabel): never {
                EarlyExit::stop($innerLabel);
            });
        });

        self::assertTrue($inner);
        self::assertFalse($outer);
    }

    public function testErrorsPassThrough(): void
    {
        $this->expectException(JqException::class);

        EarlyExit::run(static function (): never {
            throw new JqException('x');
        });
    }
}
