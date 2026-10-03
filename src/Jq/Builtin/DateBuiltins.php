<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

use LTS\PhpXq\Jq\Builtin\Date\BrokenDownTime;
use LTS\PhpXq\Jq\Builtin\Date\Strftime;
use LTS\PhpXq\Jq\Builtin\Date\Strptime;
use LTS\PhpXq\Jq\Builtin\Date\TimeZones;
use LTS\PhpXq\Jq\Builtin\Date\ZoneInfo;
use LTS\PhpXq\Jq\Builtin\Regex\NativeValue;
use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\BuiltinProviderInterface;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Json\Values;

/**
 * Date and time builtins following jq 1.8 on glibc: `mktime`, `gmtime`, `localtime`, `strftime`,
 * `strflocaltime`, `strptime`, `now` natively, and `todate`, `fromdate`, `date`, `dateadd`, `datesub`,
 * `todateiso8601`, `fromdateiso8601` in the prelude. Broken-down times are the 8 element arrays
 * `[year, month0, day, hour, minute, seconds, weekday, yearday]`; `gmtime` and `localtime` carry the
 * fractional seconds of their input in the seconds element.
 *
 * The local zone comes from the TZ environment variable (see {@see TimeZones}); `strftime` always formats
 * as UTC, `strflocaltime` in the local zone.
 *
 * @api
 */
final class DateBuiltins implements BuiltinProviderInterface
{
    private const string PRELUDE = <<<'JQ'
        def todate: strftime("%Y-%m-%dT%H:%M:%SZ");
        def fromdateiso8601: strptime("%Y-%m-%dT%H:%M:%SZ") | mktime;
        def todateiso8601: strftime("%Y-%m-%dT%H:%M:%SZ");
        def fromdate: fromdateiso8601;
        def date: todate;
        def dateadd(u; n): . + n;
        def datesub(u; n): . - n;
        JQ;

    public function registerInto(BuiltinRegistryInterface $registry): void
    {
        $registry->register(new NativeValue('now', 0, static fn (mixed $input, array $args, RuntimeContextInterface $context): mixed => $context->now()));
        $registry->register(new NativeValue('mktime', 0, static fn (mixed $input): mixed => self::mktime($input)));
        $registry->register(new NativeValue('gmtime', 0, static fn (mixed $input): mixed => self::gmtime($input, ZoneInfo::utc(), 'gmtime')));
        $registry->register(new NativeValue('localtime', 0, static fn (mixed $input): mixed => self::gmtime($input, TimeZones::local(), 'localtime')));
        $registry->register(new NativeValue('strftime', 1, static fn (mixed $input, array $args): mixed => self::strftime($input, $args[0])));
        $registry->register(new NativeValue('strflocaltime', 1, static fn (mixed $input, array $args): mixed => self::strflocaltime($input, $args[0])));
        $registry->register(new NativeValue('strptime', 1, static fn (mixed $input, array $args): mixed => self::strptime($input, $args[0])));
        $registry->addPrelude(self::PRELUDE);
    }

    /**
     * @return list<int>|list<int|float>
     *
     * @throws JqException
     */
    private static function gmtime(mixed $input, ZoneInfo $zone, string $name): array
    {
        if (!\is_int($input) && !\is_float($input) && !$input instanceof PreciseNumber) {
            throw new JqException($name . '() requires a number');
        }

        $seconds  = Values::toFloat($input);
        $time     = self::fromSeconds($seconds, $zone);
        $fraction = $seconds - floor($seconds);

        return [
            $time->year,
            $time->month,
            $time->day,
            $time->hour,
            $time->minute,
            Arithmetic::normalize($time->second + $fraction),
            $time->weekday,
            $time->yearDay,
        ];
    }

    /**
     * @throws JqException
     */
    private static function fromSeconds(float $seconds, ZoneInfo $zone): BrokenDownTime
    {
        $time = is_nan($seconds) || is_infinite($seconds) || abs($seconds) >= 9.0E18
            ? null
            : BrokenDownTime::fromEpoch((int)$seconds, $zone);

        return $time ?? throw new JqException('error converting number of seconds since epoch to datetime');
    }

    /**
     * @throws JqException
     */
    private static function mktime(mixed $input): int
    {
        if (!\is_array($input)) {
            throw new JqException('mktime requires array of 6 numbers');
        }

        $time = BrokenDownTime::fromJq($input);
        if (!$time instanceof BrokenDownTime) {
            throw new JqException('mktime requires parsed datetime inputs');
        }

        $seconds = $time->wallSeconds();
        if (-1 === $seconds) {
            throw new JqException('invalid gmtime representation');
        }

        return $seconds;
    }

    /**
     * @throws JqException
     */
    private static function strftime(mixed $input, mixed $format): string
    {
        [$time, $text] = self::timeForFormatting('strftime/1', $input, $format, ZoneInfo::utc(), false);

        return Strftime::format($text, $time);
    }

    /**
     * @throws JqException
     */
    private static function strflocaltime(mixed $input, mixed $format): string
    {
        [$time, $text] = self::timeForFormatting('strflocaltime/1', $input, $format, TimeZones::local(), true);

        return Strftime::format($text, $time);
    }

    /**
     * The shared front half of `strftime` and `strflocaltime`: a number is converted to a broken-down time
     * in the formatting zone, an array is read as given (its wall clock is then placed in the zone to find
     * the offset and abbreviation), and the checks run in jq's order.
     *
     * @return array{BrokenDownTime, string} the time and the validated format
     *
     * @throws JqException
     */
    private static function timeForFormatting(string $name, mixed $input, mixed $format, ZoneInfo $zone, bool $local): array
    {
        $fromNumber = null;
        if (\is_int($input) || \is_float($input) || $input instanceof PreciseNumber) {
            $fromNumber = self::fromSeconds(Values::toFloat($input), $zone);
        } elseif (!\is_array($input)) {
            throw new JqException($name . ' requires parsed datetime inputs');
        }

        if (!\is_string($format)) {
            throw new JqException($name . ' requires a string format');
        }

        if ($fromNumber instanceof BrokenDownTime) {
            return [$fromNumber, $format];
        }

        $time = BrokenDownTime::fromJq($input);
        if (!$time instanceof BrokenDownTime) {
            throw new JqException($name . ' requires parsed datetime inputs');
        }

        if (!$local) {
            return [$time, $format];
        }

        [$offset, $dst, $abbreviation] = $zone->at($zone->epochOfWallClock($time->wallSeconds()));

        return [
            new BrokenDownTime(
                $time->year,
                $time->month,
                $time->day,
                $time->hour,
                $time->minute,
                $time->second,
                $time->weekday,
                $time->yearDay,
                $offset,
                $abbreviation,
                $dst,
            ),
            $format,
        ];
    }

    /**
     * @return list<int|string>
     *
     * @throws JqException
     */
    private static function strptime(mixed $input, mixed $format): array
    {
        if (!\is_string($input) || !\is_string($format)) {
            throw new JqException('strptime/1 requires string inputs and arguments');
        }

        $parsed = Strptime::parse($input, $format, TimeZones::local());
        if (null === $parsed) {
            throw new JqException(\sprintf('date "%s" does not match format "%s"', $input, $format));
        }

        [$fields, $rest] = $parsed;
        if ('' !== $rest) {
            $fields[] = $rest;
        }

        return $fields;
    }
}
