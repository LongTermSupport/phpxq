<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Builtin\Regex\Cartesian;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CartesianTest extends TestCase
{
    public function testFirstFilterIsTheOutermostLoop(): void
    {
        $seen = [];
        Cartesian::each(
            null,
            static function (array $values) use (&$seen): void {
                $seen[] = $values;
            },
            FakeFilter::yielding(1, 2),
            FakeFilter::yielding('a', 'b'),
        );

        self::assertSame([[1, 'a'], [1, 'b'], [2, 'a'], [2, 'b']], $seen);
    }

    public function testNoFiltersRunsTheBodyOnce(): void
    {
        $count = 0;
        Cartesian::each(null, static function (array $values) use (&$count): void {
            self::assertSame([], $values);
            ++$count;
        });

        self::assertSame(1, $count);
    }

    public function testAFilterWithNoOutputsSkipsTheBody(): void
    {
        $count = 0;
        Cartesian::each(null, static function () use (&$count): void {
            ++$count;
        }, FakeFilter::yielding(1), FakeFilter::yielding());

        self::assertSame(0, $count);
    }

    public function testFiltersReceiveTheOriginalInput(): void
    {
        $seen = [];
        $echo = FakeFilter::from(static fn (mixed $input): array => [$input]);
        Cartesian::each('in', static function (array $values) use (&$seen): void {
            $seen[] = $values;
        }, $echo, $echo);

        self::assertSame([['in', 'in']], $seen);
    }
}
