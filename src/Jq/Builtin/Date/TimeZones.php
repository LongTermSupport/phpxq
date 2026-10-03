<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Date;

use DateTimeZone;
use Exception;

/**
 * The local time zone for `localtime`, `strflocaltime` and `%s` parsing, resolved from the TZ environment
 * variable on every call so a changed TZ is honoured. Understood: tz database names (`Europe/Paris`, with or
 * without a leading colon), abbreviations PHP knows (`EST5EDT`, `UTC`), and POSIX `NAME[+-]hh[:mm[:ss]]`
 * with no DST rules (`JST-9`). An empty TZ means UTC; an unset TZ reads /etc/localtime, /etc/timezone and
 * the date.timezone ini setting, then UTC; an unknown value falls back to UTC like glibc does.
 *
 * @internal
 */
final class TimeZones
{
    /** @var array<string, ZoneInfo> */
    private static array $resolved = [];

    private function __construct()
    {
    }

    public static function local(): ZoneInfo
    {
        $tz = getenv('TZ');

        return self::resolve(false === $tz ? null : $tz);
    }

    public static function resolve(?string $spec): ZoneInfo
    {
        $key = $spec ?? "\0unset";

        return self::$resolved[$key] ??= null === $spec ? self::hostDefault() : self::fromSpec($spec);
    }

    private static function fromSpec(string $spec): ZoneInfo
    {
        $spec = ltrim($spec, ':');
        if ('' === $spec) {
            return ZoneInfo::utc();
        }

        $named = self::named($spec);
        if (null !== $named) {
            return $named;
        }

        if (1 === preg_match('/^(?:([A-Za-z]{3,})|<([^>]+)>)([+-]?)(\d{1,2})(?::(\d{2}))?(?::(\d{2}))?/', $spec, $parts)) {
            $seconds = (int)$parts[4] * 3600 + (int)($parts[5] ?? 0) * 60 + (int)($parts[6] ?? 0);

            // POSIX offsets count west of UTC
            return ZoneInfo::fixed('-' === $parts[3] ? $seconds : -$seconds, '' !== $parts[1] ? $parts[1] : $parts[2]);
        }

        return ZoneInfo::utc();
    }

    private static function named(string $name): ?ZoneInfo
    {
        try {
            return ZoneInfo::named(new DateTimeZone($name));
        } catch (Exception) {
            return null;
        }
    }

    private static function hostDefault(): ZoneInfo
    {
        $candidates = [];
        $link       = is_link('/etc/localtime') ? readlink('/etc/localtime') : false;
        if (false !== $link && 1 === preg_match('~zoneinfo/(.+)$~', $link, $parts)) {
            $candidates[] = $parts[1];
        }

        $file = is_readable('/etc/timezone') ? file_get_contents('/etc/timezone') : false;
        if (false !== $file) {
            $candidates[] = trim($file);
        }

        $candidates[] = (string)ini_get('date.timezone');

        foreach ($candidates as $candidate) {
            $zone = '' === $candidate ? null : self::named($candidate);
            if (null !== $zone) {
                return $zone;
            }
        }

        return ZoneInfo::utc();
    }
}
