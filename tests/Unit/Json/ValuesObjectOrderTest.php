<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\Values;
use LTS\PhpXq\Tests\Support\GrowthProbe;
use LTS\PhpXq\Tests\Support\Jq\StandardProgram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * jq's ordering of objects (sorted keys first, then the values in key order), and its cost: sorting objects
 * costs about what sorting arrays of the same values costs, because each object's keys are sorted once.
 *
 * @internal
 */
#[Medium]
final class ValuesObjectOrderTest extends TestCase
{
    /**
     * Objects (and arrays) sorted per run.
     */
    private const int OBJECTS = 3000;

    /**
     * Members per object, elements per array.
     */
    private const int KEYS = 30;

    /**
     * Sorting objects re-sorted both objects' keys on every comparison, about six times the cost of sorting
     * arrays of the same values; with the keys sorted once per object it is about twice.
     */
    private const float MAX_COST_OVER_ARRAYS = 4.0;

    /**
     * @param list<string> $expected
     */
    #[DataProvider('programs')]
    public function testOrdering(string $program, string $input, array $expected): void
    {
        self::assertSame($expected, StandardProgram::outputs($program, $input));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function programs(): iterable
    {
        yield 'sort by sorted keys then values' => ['sort', '[{"b":1},{"a":2},{"a":1,"b":0},{"a":1}]', ['[{"a":1},{"a":2},{"a":1,"b":0},{"b":1}]']];
        yield 'insertion order does not matter' => ['unique', '[{"a":1,"b":2},{"b":2,"a":1},{"b":3,"a":1}]', ['[{"a":1,"b":2},{"b":3,"a":1}]']];
        yield 'numeric-looking keys sort as strings' => ['sort', '[{"9":0},{"10":0},{"10":0,"9":0}]', ['[{"10":0},{"10":0,"9":0},{"9":0}]']];
        yield 'group_by object values' => ['group_by(.k) | map(length)', '[{"k":{"x":1}},{"k":{"y":0}},{"k":{"x":1}}]', ['[2,1]']];
        yield 'equality ignores insertion order' => ['.[0] == .[1]', '[{"a":1,"b":{"c":2,"d":3}},{"b":{"d":3,"c":2},"a":1}]', ['true']];
        yield 'nested objects compare recursively' => ['sort', '[{"a":{"b":2}},{"a":{"b":1}},{"a":{"a":9}}]', ['[{"a":{"a":9}},{"a":{"b":1}},{"a":{"b":2}}]']];
    }

    public function testComparingTheSameObjectsAgainGivesTheSameOrder(): void
    {
        $small = new JsonObject(['b' => 1, 'a' => 2]);
        $large = new JsonObject(['a' => 2, 'b' => 2]);

        self::assertSame(-1, Values::compare($small, $large));
        self::assertSame(1, Values::compare($large, $small));
        self::assertSame(-1, Values::compare($small, $large));
        self::assertSame(0, Values::compare($small, new JsonObject(['a' => 2, 'b' => 1])));
    }

    public function testSortingObjectsCostsAboutWhatSortingArraysCosts(): void
    {
        $objects = static fn (): array => array_map(static fn (array $values): JsonObject => new JsonObject(self::members(...$values)), self::rows());
        $fresh   = [$objects(), $objects(), $objects()];
        $rows    = self::rows();
        $cost    = GrowthProbe::relativeCost(
            static function () use (&$fresh, $objects): void {
                $sample = array_pop($fresh) ?? $objects();
                usort($sample, Values::compare(...));
            },
            static function () use ($rows): void {
                usort($rows, Values::compare(...));
            },
        );

        self::assertLessThan(self::MAX_COST_OVER_ARRAYS, $cost);
    }

    /**
     * @return list<list<int>> OBJECTS rows of KEYS values each
     */
    private static function rows(): array
    {
        $rows = [];
        for ($row = 0; $row < self::OBJECTS; ++$row) {
            $values = [];
            for ($column = 0; $column < self::KEYS; ++$column) {
                $values[] = ($row * 31 + $column) % 97;
            }

            $rows[] = $values;
        }

        return $rows;
    }

    /**
     * @return array<string, int> the values under keys whose insertion order differs from their sorted order
     */
    private static function members(int ...$values): array
    {
        $members = [];
        foreach (array_values($values) as $column => $value) {
            $members['key' . (($column * 7) % self::KEYS)] = $value;
        }

        return $members;
    }
}
