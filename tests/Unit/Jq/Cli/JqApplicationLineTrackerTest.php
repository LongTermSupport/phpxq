<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\LineTracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationLineTrackerTest extends TestCase
{
    /**
     * @param list<int> $expected the line after each value, in order
     */
    #[DataProvider('provideTexts')]
    public function testLineAfterEachValue(string $text, array $expected): void
    {
        $tracker = new LineTracker($text);

        $actual = [];
        foreach (array_keys($expected) as $index) {
            $actual[] = $tracker->lineOfValue($index + 1);
        }

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function provideTexts(): iterable
    {
        yield 'one value per line' => ["1\n2\n3\n", [1, 2, 3]];

        yield 'no trailing newline' => ["1\n2\n3", [1, 2, 2]];

        yield 'values sharing a line' => ["1 2 3\n4", [1, 1, 1, 1]];

        yield 'multi-line value counts through its last line' => ["[1,\n2]\n7", [2, 2]];

        yield 'single value without newline' => ['{"a":1}', [0]];

        yield 'blank lines count' => ["1\n\n\n2", [1, 3]];
    }

    public function testAskingOutOfOrderGivesTheSameAnswers(): void
    {
        $tracker = new LineTracker("1\n2\n3\n");

        self::assertSame(3, $tracker->lineOfValue(3));
        self::assertSame(1, $tracker->lineOfValue(1));
        self::assertSame(3, $tracker->lineOfValue(3));
        self::assertSame(2, $tracker->lineOfValue(2));
        self::assertSame(2, $tracker->lineOfValue(2));
    }

    public function testBeforeTheFirstValueIsLineZero(): void
    {
        self::assertSame(0, new LineTracker("1\n")->lineOfValue(0));
    }

    public function testAskingForMoreValuesThanThereAreStopsAtTheEnd(): void
    {
        self::assertSame(1, new LineTracker("1\n")->lineOfValue(5));
    }

    public function testALongLineIsScannedOnce(): void
    {
        $tracker = new LineTracker(str_repeat('1 ', 30000) . "\n");

        self::assertSame(1, $tracker->lineOfValue(30000));
        self::assertSame(1, $tracker->lineOfValue(10));
    }
}
