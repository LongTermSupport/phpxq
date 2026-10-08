<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Tests\Support\GrowthProbe;
use LTS\PhpXq\Tests\Support\Jq\StandardProgram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * jq programs whose work is linear in the size of their input stay linear: each program reads its size as
 * the input number, and its run time at four times that size must grow about fourfold, not sixteenfold.
 *
 * @internal
 */
#[Medium]
final class LinearWorkloadTest extends TestCase
{
    #[DataProvider('linearPrograms')]
    public function testProgramScalesLinearly(string $program, int $baseSize, string $expectedAtBase): void
    {
        $run = StandardProgram::compile($program);

        self::assertSame([$expectedAtBase], $run($baseSize));
        self::assertLessThan(GrowthProbe::LINEAR_CEILING, GrowthProbe::growth($run, $baseSize));
    }

    /**
     * @return iterable<string, array{string, int, string}> program, base size, its output at the base size
     */
    public static function linearPrograms(): iterable
    {
        yield 'indices in a non-ASCII string' => ['. as $n | "é" * $n | indices("é") | length', 3000, '3000'];
        yield 'every one-character slice of a non-ASCII string' => ['. as $n | ("aé" * ($n / 2)) as $s | [range($n) as $i | $s[$i:$i + 1]] | length', 1000, '1000'];
    }
}
