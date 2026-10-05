<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\InputItem;
use LTS\PhpXq\Jq\Cli\LineTracker;
use LTS\PhpXq\Jq\Cli\UsageText;
use PHPUnit\Framework\TestCase;

/**
 * What each way of building an input item records, and the version line.
 *
 * @internal
 */
final class JqApplicationInputItemTest extends TestCase
{
    public function testAValueRecordsItsPositionAndNoError(): void
    {
        $item = InputItem::value([1], 'file.json', 3);

        self::assertSame([1], $item->value);
        self::assertNull($item->error);
        self::assertFalse($item->fatal);
        self::assertFalse($item->isError());
        self::assertSame('file.json', $item->filename);
        self::assertSame(3, $item->line);
        self::assertNull($item->tracker);
        self::assertSame(0, $item->ordinal);
        self::assertSame(3, $item->lineNumber());
    }

    public function testATrackedValueAsksItsTrackerForTheLine(): void
    {
        $tracker = new LineTracker("1\n2\n3\n", 10);
        $item    = InputItem::tracked('v', null, $tracker, 2);

        self::assertSame('v', $item->value);
        self::assertNull($item->error);
        self::assertFalse($item->fatal);
        self::assertNull($item->filename);
        self::assertSame(0, $item->line);
        self::assertSame($tracker, $item->tracker);
        self::assertSame(2, $item->ordinal);
        self::assertSame(12, $item->lineNumber());
    }

    public function testAnErrorCarriesItsMessageAndLine(): void
    {
        $fatal = InputItem::error('bad', true, 'in.json', 4);

        self::assertNull($fatal->value);
        self::assertSame('bad', $fatal->error);
        self::assertTrue($fatal->fatal);
        self::assertTrue($fatal->isError());
        self::assertSame('in.json', $fatal->filename);
        self::assertSame(4, $fatal->lineNumber());
        self::assertNull($fatal->tracker);
        self::assertSame(0, $fatal->ordinal);

        $recoverable = InputItem::error('skipped', false, null, 0);

        self::assertFalse($recoverable->fatal);
        self::assertSame(0, $recoverable->lineNumber());
        self::assertNull($recoverable->filename);
    }

    public function testTheBuildConfigurationLineNamesThePhpVersion(): void
    {
        self::assertSame('phpxq ' . \PHP_VERSION . " pure PHP\n", UsageText::buildConfiguration());
    }

    public function testTheVersionLineNamesTheTargetJq(): void
    {
        self::assertSame("jq-1.8.2\n", UsageText::version());
    }
}
