<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\Compare;
use LTS\PhpXq\Yq\Runtime\Operators\SortingCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The native-sort fast path of sort_by and group_by must order exactly as the general comparison does:
 * stable ties, byte order for strings, numeric order across ints, floats and other bases, and a fall back
 * to the general comparison for dates, mixed types, NaN and several keys.
 *
 * @internal
 */
#[CoversClass(SortingCalls::class)]
#[CoversClass(Compare::class)]
final class SortingCallsOrderTest extends TestCase
{
    #[DataProvider('orders')]
    public function testOrder(string $expression, string $input, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, $input, false));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function orders(): iterable
    {
        yield 'strings use byte order' => [
            'sort_by(.a)',
            "- a: b\n- a: B\n- a: a\n- a: _\n- a: Z\n",
            "- a: B\n- a: Z\n- a: _\n- a: a\n- a: b\n",
        ];
        yield 'ints and floats order numerically and ties keep input order' => [
            'sort_by(.a)',
            "- {a: 3, i: 0}\n- {a: 1.5, i: 1}\n- {a: 2, i: 2}\n- {a: -1, i: 3}\n- {a: 1.5, i: 4}\n",
            "- {a: -1, i: 3}\n- {a: 1.5, i: 1}\n- {a: 1.5, i: 4}\n- {a: 2, i: 2}\n- {a: 3, i: 0}\n",
        ];
        yield 'other number bases order by value' => [
            'sort_by(.a)',
            "- {a: 0x10, i: 0}\n- {a: 5, i: 1}\n- {a: 1e1, i: 2}\n",
            "- {a: 5, i: 1}\n- {a: 1e1, i: 2}\n- {a: 0x10, i: 0}\n",
        ];
        yield 'group_by groups equal numbers in key order' => [
            'group_by(.a)',
            "- {a: 2, i: 0}\n- {a: 1, i: 1}\n- {a: 2, i: 2}\n- {a: 1, i: 3}\n",
            "- - {a: 1, i: 1}\n  - {a: 1, i: 3}\n- - {a: 2, i: 0}\n  - {a: 2, i: 2}\n",
        ];
        yield 'equal strings keep their input order' => [
            'sort_by(.a)',
            "- {a: alpha, i: 0}\n- {a: beta, i: 1}\n- {a: alpha, i: 2}\n- {a: beta, i: 3}\n",
            "- {a: alpha, i: 0}\n- {a: alpha, i: 2}\n- {a: beta, i: 1}\n- {a: beta, i: 3}\n",
        ];
        yield 'quoted digit strings compare as text' => [
            'sort_by(.a)',
            "- a: \"10\"\n- a: \"9\"\n- a: \"1\"\n",
            "- a: \"1\"\n- a: \"10\"\n- a: \"9\"\n",
        ];
        yield 'timestamps order by instant' => [
            'sort_by(.a)',
            "- a: 2024-01-02T00:00:00Z\n- a: 2023-12-31T00:00:00Z\n- a: 2024-01-01T10:00:00+05:00\n",
            "- a: 2023-12-31T00:00:00Z\n- a: 2024-01-01T10:00:00+05:00\n- a: 2024-01-02T00:00:00Z\n",
        ];
        yield 'mixed types order null, booleans, numbers, strings' => [
            'sort_by(.a)',
            "- a: x\n- a: 3\n- a: true\n- a: ~\n- a: false\n- a: 1\n",
            "- a: ~\n- a: false\n- a: true\n- a: 1\n- a: 3\n- a: x\n",
        ];
        yield 'a mapping sorts by entry value' => [
            'sort_by(.a)',
            "k1: {a: b}\nk2: {a: a}\n",
            "k2: {a: a}\nk1: {a: b}\n",
        ];
        yield 'two key expressions' => [
            'sort_by(.a, .b)',
            "- {a: 1, b: 2}\n- {a: 1, b: 1}\n- {a: 0, b: 9}\n",
            "- {a: 0, b: 9}\n- {a: 1, b: 1}\n- {a: 1, b: 2}\n",
        ];
        yield 'an empty sequence' => ['sort_by(.a)', "[]\n", "[]\n"];
        yield 'infinities and NaN' => [
            'sort_by(.a)',
            "- a: .nan\n- a: 1\n- a: .inf\n- a: -.inf\n",
            "- a: -.inf\n- a: 1\n- a: .inf\n- a: .nan\n",
        ];
        yield 'group_by with strings and a number' => [
            'group_by(.a)',
            "- a: x\n- a: y\n- a: x\n- a: 1\n",
            "- - a: 1\n- - a: x\n  - a: x\n- - a: y\n",
        ];
        yield 'empty strings sort first' => [
            'sort_by(.a)',
            "- a: \"\"\n- a: b\n- a: \"\"\n",
            "- a: \"\"\n- a: \"\"\n- a: b\n",
        ];
        yield 'sort of plain strings' => ['sort', "- b\n- a\n- c\n", "- a\n- b\n- c\n"];
    }
}
