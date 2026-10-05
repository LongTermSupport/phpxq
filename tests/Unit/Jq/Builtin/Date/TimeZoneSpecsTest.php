<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Date;

use LTS\PhpXq\Jq\Builtin\Date\TimeZones;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * POSIX style TZ specs with minutes and seconds, and the silence of an unknown zone name.
 *
 * @internal
 */
final class TimeZoneSpecsTest extends TestCase
{
    /**
     * @param array{int, bool, string} $expected
     */
    #[DataProvider('posixSpecs')]
    public function testPosixSpecs(string $spec, array $expected): void
    {
        self::assertSame($expected, TimeZones::resolve($spec)->at(0), $spec);
    }

    /**
     * @return iterable<string, array{string, array{int, bool, string}}>
     */
    public static function posixSpecs(): iterable
    {
        yield 'hours minutes and seconds' => ['FOO5:30:15', [-19815, false, 'FOO']];
        yield 'east of UTC with seconds' => ['BAR-5:30:15', [19815, false, 'BAR']];
        yield 'hours and minutes' => ['BAZ5:30', [-19800, false, 'BAZ']];
        yield 'hours only' => ['QUX7', [-25200, false, 'QUX']];
        yield 'two digit hours' => ['QUUX-12', [43200, false, 'QUUX']];
        yield 'seconds without minutes digits' => ['ABC1:00:01', [-3601, false, 'ABC']];
        yield 'quoted name with seconds' => ['<+0530>-5:30:30', [19830, false, '+0530']];
        yield 'quoted name without sign' => ['<ZED>2', [-7200, false, 'ZED']];
    }

    public function testAnUnknownZoneNameRaisesNoWarning(): void
    {
        error_clear_last();

        $zone = TimeZones::resolve('Nowhere/AtAll');

        self::assertNull(error_get_last());
        self::assertSame([0, false, 'UTC'], $zone->at(0));
    }
}
