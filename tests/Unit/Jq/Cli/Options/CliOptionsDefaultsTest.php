<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli\Options;

use LTS\PhpXq\Jq\Cli\Options\CliOptions;
use PHPUnit\Framework\TestCase;

/**
 * Every default of the options record, and the layout rules that combine them.
 *
 * @internal
 */
final class CliOptionsDefaultsTest extends TestCase
{
    public function testEveryFlagIsOffByDefault(): void
    {
        $options = new CliOptions();

        foreach (['nullInput', 'rawInput', 'slurp', 'rawOutput', 'rawOutput0', 'joinOutput', 'ascii', 'sortKeys', 'exitStatus', 'seq', 'stream', 'streamErrors', 'unbuffered', 'fromFile', 'debugDumpDisasm'] as $flag) {
            self::assertFalse($options->{$flag}, $flag);
        }

        self::assertNull($options->program);
        self::assertNull($options->color);
        self::assertSame([], $options->files);
    }

    public function testFlatPrettyNeedsPrettyNoTabAndIndentZero(): void
    {
        self::assertTrue(new CliOptions(indent: 0)->isFlatPretty());
        self::assertFalse(new CliOptions(pretty: false, indent: 0)->isFlatPretty());
        self::assertFalse(new CliOptions(tab: true, indent: 0)->isFlatPretty());
        self::assertFalse(new CliOptions(pretty: false, tab: true, indent: 0)->isFlatPretty());
        self::assertFalse(new CliOptions(indent: 1)->isFlatPretty());
        self::assertFalse(new CliOptions(pretty: false, indent: 1)->isFlatPretty());
    }

    public function testTabsAreEncodedWithOneIndentUnit(): void
    {
        $encode = new CliOptions(tab: true, indent: 5)->encodeOptions(null);

        self::assertTrue($encode->useTab);
        self::assertSame(1, $encode->indent);
    }

    public function testADuplicateOfAPredefinedNameKeepsTheListSequential(): void
    {
        $names = new CliOptions(named: ['ENV' => 'x', 'z' => 'y'])->globalNames();

        self::assertSame(['ENV', '__prog_args', 'ARGS', 'z'], $names);
    }
}
