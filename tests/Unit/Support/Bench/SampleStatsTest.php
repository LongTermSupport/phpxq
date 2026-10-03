<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use LTS\PhpXq\Tests\Support\Bench\SampleStats;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SampleStatsTest extends TestCase
{
    public function testCoefficientOfVariationIsRelativeToMean(): void
    {
        $stats = new SampleStats(4, 8.0, 10.0, 10.0, 12.0, 12.0, 2.0);

        self::assertSame(20.0, $stats->coefficientOfVariationPercent());
    }

    public function testToArrayRoundTripsFields(): void
    {
        $array = new SampleStats(4, 1.0, 2.0, 3.0, 4.0, 5.0, 6.0)->toArray();

        self::assertSame(
            ['count' => 4, 'minMs' => 1.0, 'medianMs' => 2.0, 'meanMs' => 3.0, 'p95Ms' => 4.0, 'maxMs' => 5.0, 'stddevMs' => 6.0],
            $array,
        );
    }
}
