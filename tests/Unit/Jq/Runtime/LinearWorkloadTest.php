<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Json\JsonObject;
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
        yield 'reduce appending to an array' => ['. as $n | reduce range($n) as $i ([]; . + [$i]) | length', 4000, '4000'];
        yield 'reduce plus-assigning to an array' => ['. as $n | reduce range($n) as $i ([]; . += [$i]) | length', 4000, '4000'];
        yield 'reduce setting object keys' => ['. as $n | reduce range($n) as $i ({}; .[$i | tostring] = $i) | length', 12000, '12000'];
        yield 'reduce merging one-key objects' => ['. as $n | reduce range($n) as $i ({}; . + {"k\($i)": $i}) | length', 4000, '4000'];
        yield 'foreach appending to an array' => ['. as $n | [foreach range($n) as $i ([]; . + [$i]; length)] | length', 4000, '4000'];
        yield 'add over single-member objects' => ['. as $n | [range($n) | {("k\(.)"): .}] | add | length', 4000, '4000'];
        yield 'adding a large object to an empty one' => ['. as $n | [range($n) | {key: "k\(.)", value: .}] | from_entries | {} + . | length', 4000, '4000'];
        yield 'deep merge of a large object into a small one' => ['. as $n | [range($n) | {key: "k\(.)", value: {v: .}}] | from_entries | {k0: {w: 0}} * . | [length, .k0] | tojson', 4000, '"[4000,{\"w\":0,\"v\":0}]"'];
        yield 'gsub with long text between matches' => ['. as $n | ("x" * 1000 + "a") * $n | gsub("a"; "b") | length', 500, '500500'];
        yield 'every one-character slice of a non-ASCII string' => ['. as $n | ("aé" * ($n / 2)) as $s | [range($n) as $i | $s[$i:$i + 1]] | length', 1000, '1000'];
    }

    /**
     * The object is built outside jq, so that the deletion dominates the run time.
     */
    public function testDeletingEveryMemberOfAnObjectScalesLinearly(): void
    {
        $run    = StandardProgram::compile('del(.[]) | length');
        $object = static function (int $size): JsonObject {
            $members = [];
            for ($i = 0; $i < $size; ++$i) {
                $members['k' . $i] = $i;
            }

            return new JsonObject($members);
        };

        self::assertSame(['0'], $run($object(3)));
        self::assertLessThan(GrowthProbe::LINEAR_CEILING, GrowthProbe::growth(static fn (int $size): array => $run($object($size)), 5000));
    }
}
