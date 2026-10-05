<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\Downstream;
use LTS\PhpXq\Jq\Runtime\JqException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Downstream::class)]
final class DownstreamTest extends TestCase
{
    public function testIsActiveOnlyWhileTheContinuationRuns(): void
    {
        $downstream = new Downstream();
        $observed   = [];
        $guarded    = $downstream->guard(static function () use ($downstream, &$observed): void {
            $observed[] = $downstream->active();
        });

        self::assertFalse($downstream->active());
        $guarded(1);
        self::assertSame([true], $observed);
        self::assertFalse($downstream->active());
    }

    public function testStaysActiveWhenTheContinuationThrows(): void
    {
        $downstream = new Downstream();
        $guarded    = $downstream->guard(static function (): never {
            throw new JqException('x');
        });

        try {
            $guarded(1);
            self::fail('expected an exception');
        } catch (JqException $jqException) {
            self::assertSame('x', $jqException->getMessage());
            self::assertTrue($downstream->active());
        }
    }

    public function testPathVariantPassesNoPathThrough(): void
    {
        $downstream = new Downstream();
        $seen       = [];
        $guarded    = $downstream->guardPaths(static function (?array $path, mixed $value) use (&$seen): void {
            $seen[] = [$path, $value];
        });

        $guarded(['x', 'y'], 1);
        $guarded(null, 2);

        self::assertSame([[['x', 'y'], 1], [null, 2]], $seen);
    }

    public function testPathVariantIsActiveWhileTheContinuationRuns(): void
    {
        $downstream = new Downstream();
        $observed   = [];
        $guarded    = $downstream->guardPaths(static function () use ($downstream, &$observed): void {
            $observed[] = $downstream->active();
        });

        $guarded(['a'], 1);

        self::assertSame([true], $observed);
        self::assertFalse($downstream->active());
    }

    public function testPathVariant(): void
    {
        $downstream = new Downstream();
        $seen       = [];
        $guarded    = $downstream->guardPaths(static function (?array $path, mixed $value) use (&$seen): void {
            $seen[] = [$path, $value];
        });

        $guarded(['a'], 1);

        self::assertSame([[['a'], 1]], $seen);
        self::assertFalse($downstream->active());
    }
}
