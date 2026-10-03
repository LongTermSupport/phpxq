<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use InvalidArgumentException;
use LTS\PhpXq\Tests\Support\Bench\StatsCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class StatsCalculatorTest extends TestCase
{
    public function testSummarisesOddSampleCount(): void
    {
        $stats = new StatsCalculator()->summarise([5.0, 1.0, 3.0, 2.0, 4.0]);

        self::assertSame(5, $stats->count);
        self::assertSame(1.0, $stats->minMs);
        self::assertSame(5.0, $stats->maxMs);
        self::assertSame(3.0, $stats->medianMs);
        self::assertSame(3.0, $stats->meanMs);
        self::assertEqualsWithDelta(1.5811, $stats->stddevMs, 0.0001);
        self::assertEqualsWithDelta(4.8, $stats->p95Ms, 0.0001);
    }

    public function testMedianInterpolatesForEvenCount(): void
    {
        self::assertSame(2.5, new StatsCalculator()->summarise([1.0, 2.0, 3.0, 4.0])->medianMs);
    }

    public function testSingleSampleHasNoVariance(): void
    {
        $stats = new StatsCalculator()->summarise([7.0]);

        self::assertSame(7.0, $stats->medianMs);
        self::assertSame(7.0, $stats->p95Ms);
        self::assertSame(0.0, $stats->stddevMs);
        self::assertSame(0.0, $stats->coefficientOfVariationPercent());
    }

    public function testCoefficientOfVariation(): void
    {
        $stats = new StatsCalculator()->summarise([9.0, 11.0]);

        self::assertEqualsWithDelta(14.142, $stats->coefficientOfVariationPercent(), 0.001);
    }

    public function testZeroMeanHasZeroCoefficientOfVariation(): void
    {
        self::assertSame(0.0, new StatsCalculator()->summarise([0.0, 0.0])->coefficientOfVariationPercent());
    }

    public function testToArrayExposesEveryField(): void
    {
        $array = new StatsCalculator()->summarise([1.0, 3.0])->toArray();

        self::assertSame(2, $array['count']);
        self::assertSame(1.0, $array['minMs']);
        self::assertSame(3.0, $array['maxMs']);
        self::assertSame(2.0, $array['medianMs']);
    }

    public function testRejectsEmptySamples(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StatsCalculator()->summarise([]);
    }
}
