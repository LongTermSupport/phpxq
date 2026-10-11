<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin;

use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\InputProviderInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;
use LTS\PhpXq\Tests\Support\Jq\EagerBuiltins;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
final class DateBuiltinsTest extends TestCase
{
    private string|false $originalZone = false;

    private ?BuiltinRegistryInterface $registry = null;

    protected function setUp(): void
    {
        $this->originalZone = getenv('TZ');
        putenv('TZ=UTC');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->originalZone ? 'TZ' : 'TZ=' . $this->originalZone);
    }

    public function testRegistersNativesAndPrelude(): void
    {
        $registry = $this->registry();

        foreach (['now/0', 'mktime/0', 'gmtime/0', 'localtime/0', 'strftime/1', 'strflocaltime/1', 'strptime/1'] as $signature) {
            [$name, $arity] = explode('/', $signature);
            self::assertNotNull($registry->lookup($name, (int)$arity), $signature);
        }

        foreach (['def todate:', 'def fromdate:', 'def date:', 'def dateadd(u; n)', 'def datesub(u; n)', 'def todateiso8601:', 'def fromdateiso8601:'] as $definition) {
            self::assertStringContainsString($definition, $registry->prelude());
        }
    }

    public function testNowUsesTheContextClock(): void
    {
        self::assertSame(1700000000.5, $this->call('now', null));
    }

    public function testGmtime(): void
    {
        self::assertSame([2015, 2, 5, 23, 51, 47, 4, 63], $this->call('gmtime', 1425599507));
        self::assertSame([2015, 2, 5, 23, 51, 47.25, 4, 63], $this->call('gmtime', 1425599507.25));
        self::assertSame([1970, 0, 1, 0, 0, 0, 4, 0], $this->call('gmtime', 0));
        self::assertSame([1969, 11, 31, 23, 59, 59, 3, 364], $this->call('gmtime', -1));
        self::assertSame([2000, 1, 29, 12, 0, 0, 2, 59], $this->call('gmtime', 951825600));
    }

    public function testGmtimeErrors(): void
    {
        self::assertSame('gmtime() requires a number', $this->errorOf('gmtime', 'a'));
        self::assertSame('error converting number of seconds since epoch to datetime', $this->errorOf('gmtime', 1.0E300));
        self::assertSame('error converting number of seconds since epoch to datetime', $this->errorOf('gmtime', NAN));
    }

    public function testMktime(): void
    {
        self::assertSame(1425599507, $this->call('mktime', [2015, 2, 5, 23, 51, 47, 4, 63]));
        self::assertSame(1726876800, $this->call('mktime', [2024, 8, 21]));
        self::assertSame(1425599507, $this->call('mktime', [2015.9, 2.9, 5.9, 23.9, 51.9, 47.9]));
    }

    public function testMktimeNormalisesOverflowingFields(): void
    {
        self::assertSame($this->call('mktime', [2016, 0, 1, 0, 0, 0]), $this->call('mktime', [2015, 12, 1, 0, 0, 0]));
        self::assertSame($this->call('mktime', [2016, 2, 1, 0, 0, 0]), $this->call('mktime', [2016, 1, 30, 0, 0, 0]));
        self::assertSame($this->call('mktime', [2016, 0, 1, 0, 0, 0]), $this->call('mktime', [2015, 11, 31, 24, 0, 0]));
    }

    public function testMktimeErrors(): void
    {
        self::assertSame('mktime requires array of 6 numbers', $this->errorOf('mktime', 'x'));
        self::assertSame('mktime requires parsed datetime inputs', $this->errorOf('mktime', ['a', 1, 2, 3, 4, 5, 6, 7]));
        self::assertSame('invalid gmtime representation', $this->errorOf('mktime', [1969, 11, 31, 23, 59, 59]));
    }

    public function testStrftime(): void
    {
        self::assertSame('2015-03-05T23:51:47Z', $this->call('strftime', [2015, 2, 5, 23, 51, 47, 4, 63], '%Y-%m-%dT%H:%M:%SZ'));
        self::assertSame('Tuesday, June 30, 2015', $this->call('strftime', 1435677542.822351, '%A, %B %d, %Y'));
        self::assertSame('2024-03-15T00:00:00Z', $this->call('strftime', [2024, 2, 15], '%Y-%m-%dT%H:%M:%SZ'));
        self::assertSame('', $this->call('strftime', 0, ''));
    }

    public function testStrftimeIsAlwaysUtc(): void
    {
        putenv('TZ=Europe/Paris');

        self::assertSame('2024-11-14 23:35:41 +0000 UTC', $this->call('strftime', 1731627341, '%F %T %z %Z'));
    }

    public function testStrftimeErrorsFollowJqsOrder(): void
    {
        self::assertSame('strftime/1 requires parsed datetime inputs', $this->errorOf('strftime', ['a', 1, 2, 3, 4, 5, 6, 7], '%Y'));
        self::assertSame('strftime/1 requires parsed datetime inputs', $this->errorOf('strftime', 'x', '%Y'));
        self::assertSame('strftime/1 requires a string format', $this->errorOf('strftime', 0, []));
        self::assertSame('strftime/1 requires a string format', $this->errorOf('strftime', [2000, 0, 1], 5));
    }

    public function testLocaltimeAndStrflocaltime(): void
    {
        putenv('TZ=Europe/Paris');

        self::assertSame([2025, 5, 21, 12, 0, 0, 6, 171], $this->call('localtime', 1750500000));
        self::assertSame('2025-06-21 12:00:00 +0200 CEST', $this->call('strflocaltime', 1750500000, '%F %T %z %Z'));
        self::assertSame('2024-11-15 00:35:41 +0100 CET', $this->call('strflocaltime', 1731627341, '%F %T %z %Z'));
        self::assertSame('2025-06-21 12:00:00 +0200 CEST', $this->call('strflocaltime', [2025, 5, 21, 12, 0, 0, 6, 171], '%F %T %z %Z'));
    }

    public function testStrflocaltimeInOtherZones(): void
    {
        putenv('TZ=Asia/Tokyo');
        self::assertSame('2024-11-15 08:35:41 +0900 JST', $this->call('strflocaltime', 1731627341, '%F %T %z %Z'));

        putenv('TZ=Etc/GMT+7');
        self::assertSame('2024-11-14T16:35:41-0700', $this->call('strflocaltime', 1731627341, '%FT%T%z'));
        self::assertSame('2024-11-14T23:35:41', $this->call('strftime', 1731627341, '%FT%T'));
    }

    public function testStrflocaltimeErrors(): void
    {
        self::assertSame('strflocaltime/1 requires parsed datetime inputs', $this->errorOf('strflocaltime', ['a', 1, 2, 3, 4, 5, 6, 7], '%Y'));
        self::assertSame('strflocaltime/1 requires a string format', $this->errorOf('strflocaltime', 0, new stdClass()));
    }

    public function testStrptime(): void
    {
        self::assertSame([2015, 2, 5, 23, 51, 47, 4, 63], $this->call('strptime', '2015-03-05T23:51:47Z', '%Y-%m-%dT%H:%M:%SZ'));
    }

    public function testStrptimeErrors(): void
    {
        self::assertSame('strptime/1 requires string inputs and arguments', $this->errorOf('strptime', 1, '%Y'));
        self::assertSame('strptime/1 requires string inputs and arguments', $this->errorOf('strptime', '2015', 1));
        self::assertSame('date "x" does not match format "%Y"', $this->errorOf('strptime', 'x', '%Y'));
        self::assertSame('date "2015-03-05T23:51:47" does not match format "%Y-%m-%dT%H:%M:%SZ"', $this->errorOf('strptime', '2015-03-05T23:51:47', '%Y-%m-%dT%H:%M:%SZ'));
    }

    public function testStrptimeAppendsTrailingTextAfterWhitespace(): void
    {
        self::assertSame([2015, 2, 5, 0, 0, 0, 4, 63, ' tail'], $this->call('strptime', '2015-03-05 tail', '%Y-%m-%d'));
        self::assertSame('date "2015-03-05tail" does not match format "%Y-%m-%d"', $this->errorOf('strptime', '2015-03-05tail', '%Y-%m-%d'));
    }

    /**
     * Every 11th day (coprime to 7, so every weekday and every year is hit) plus the final day; the daily
     * sweep is the upstream jq.test case run by the conformance suite.
     */
    public function testDayOfWeekAndYearRoundTripAcrossSixtySevenYears(): void
    {
        $base = $this->call('mktime', $this->call('strptime', '1970-03-01T01:02:03Z', '%Y-%m-%dT%H:%M:%SZ'));
        self::assertIsInt($base);

        $lastDay = 365 * 67 - 1;
        $days    = [...range(0, $lastDay, 11), $lastDay];
        $last    = null;
        foreach ($days as $day) {
            $text = $this->call('strftime', $this->call('gmtime', $base + 86400 * $day), '%Y-%m-%dT%H:%M:%SZ');
            $last = $this->call('strptime', $text, '%Y-%m-%dT%H:%M:%SZ');
        }

        self::assertSame([2037, 1, 11, 1, 2, 3, 3, 41], $last);
    }

    private function registry(): BuiltinRegistryInterface
    {
        if (!$this->registry instanceof BuiltinRegistryInterface) {
            $this->registry = new DefaultBuiltinRegistry();
            EagerBuiltins::date($this->registry);
        }

        return $this->registry;
    }

    private function call(string $name, mixed $input, mixed ...$args): mixed
    {
        $builtin = $this->registry()->lookup($name, \count($args));
        self::assertInstanceOf(ValueBuiltinInterface::class, $builtin);

        return $builtin->call($this->context(), $input, ...$args);
    }

    private function errorOf(string $name, mixed $input, mixed ...$args): string
    {
        try {
            $this->call($name, $input, ...$args);
        } catch (JqException $jqException) {
            return $jqException->getMessage();
        }

        self::fail('Expected a JqException');
    }

    private function context(): RuntimeContextInterface
    {
        return new class implements RuntimeContextInterface {
            public function inputs(): InputProviderInterface
            {
                throw new JqException('no inputs');
            }

            public function globals(): array
            {
                return [];
            }

            public function inputFilename(): ?string
            {
                return null;
            }

            public function libraryPaths(): array
            {
                return [];
            }

            public function debug(mixed $value): void
            {
            }

            public function writeStderr(mixed $value): void
            {
            }

            public function now(): float
            {
                return 1700000000.5;
            }
        };
    }
}
