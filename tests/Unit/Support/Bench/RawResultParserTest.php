<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Support\Bench;

use InvalidArgumentException;
use LTS\PhpXq\Tests\Support\Bench\Measurement;
use LTS\PhpXq\Tests\Support\Bench\RawResultParser;
use LTS\PhpXq\Tests\Support\Bench\StatsCalculator;
use LTS\PhpXq\Tests\Support\Bench\Target;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RawResultParserTest extends TestCase
{
    public function testParsesTargetLines(): void
    {
        $tsv     = "phpxq-jq\tjq\tsubject\t0.1.0\tphp bin/phpxq jq\njq\tjq\treference\tjq-1.6\t/usr/bin/jq\n";
        $targets = new RawResultParser(new StatsCalculator())->targets($tsv);

        self::assertCount(2, $targets);
        self::assertSame(Target::ROLE_SUBJECT, $targets[0]->role);
        self::assertSame('jq-1.6', $targets[1]->version);
        self::assertSame('/usr/bin/jq', $targets[1]->command);
    }

    public function testParsesMeasurementsAndComputesStats(): void
    {
        $tsv          = "phpxq-jq\tjq:startup\tok\t12\t10.5,11.5,12.5\t\n";
        $measurements = new RawResultParser(new StatsCalculator())->measurements($tsv);

        self::assertCount(1, $measurements);
        self::assertSame('phpxq-jq', $measurements[0]->targetId);
        self::assertSame('jq:startup', $measurements[0]->workloadId);
        self::assertSame(Measurement::STATUS_OK, $measurements[0]->status);
        self::assertSame(12, $measurements[0]->outputBytes);
        self::assertSame([10.5, 11.5, 12.5], $measurements[0]->samplesMs);
        self::assertSame(11.5, $measurements[0]->stats?->medianMs);
    }

    public function testNonOkRowsCarryNoStatsButKeepTheNote(): void
    {
        $tsv          = "phpxq-jq\tjq:startup\tnot-implemented\t0\t\tjq: not implemented\n";
        $measurements = new RawResultParser(new StatsCalculator())->measurements($tsv);

        self::assertSame(Measurement::STATUS_NOT_IMPLEMENTED, $measurements[0]->status);
        self::assertSame([], $measurements[0]->samplesMs);
        self::assertNull($measurements[0]->stats);
        self::assertSame('jq: not implemented', $measurements[0]->note);
    }

    public function testBlankLinesAreSkipped(): void
    {
        self::assertSame([], new RawResultParser(new StatsCalculator())->measurements("\n\n"));
    }

    public function testMalformedLinesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RawResultParser(new StatsCalculator())->measurements("only\tthree\tfields\n");
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RawResultParser(new StatsCalculator())->measurements("t\tw\tmystery\t0\t\t\n");
    }
}
