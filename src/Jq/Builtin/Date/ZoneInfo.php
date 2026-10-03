<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Date;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A time zone as the date builtins need it: the offset, DST flag and abbreviation at an instant, and the
 * instant of a local wall-clock time. Either a named zone (the tz database, through PHP) or a fixed offset.
 *
 * @internal
 */
final readonly class ZoneInfo
{
    private function __construct(
        private ?DateTimeZone $zone,
        private int $fixedOffset,
        private string $fixedAbbreviation,
    ) {
    }

    public static function utc(): self
    {
        return new self(null, 0, 'UTC');
    }

    public static function fixed(int $offsetSeconds, string $abbreviation): self
    {
        return new self(null, $offsetSeconds, $abbreviation);
    }

    public static function named(DateTimeZone $zone): self
    {
        return new self($zone, 0, '');
    }

    /**
     * @return array{int, bool, string} offset east of UTC in seconds, whether DST is in effect, abbreviation
     */
    public function at(int $epoch): array
    {
        if (null === $this->zone) {
            return [$this->fixedOffset, false, $this->fixedAbbreviation];
        }

        $moment = (new DateTimeImmutable('@' . $epoch))->setTimezone($this->zone);

        return [$moment->getOffset(), '1' === $moment->format('I'), $moment->format('T')];
    }

    /**
     * The instant at which a zone's wall clock reads $wallSeconds (seconds since the epoch as if it were UTC).
     * A time skipped by a DST change uses the offset before it; an ambiguous one uses the offset after.
     */
    public function epochOfWallClock(int $wallSeconds): int
    {
        if (null === $this->zone) {
            return $wallSeconds - $this->fixedOffset;
        }

        $offset = $this->at($wallSeconds)[0];
        $guess  = $wallSeconds - $offset;
        $actual = $this->at($guess)[0];

        return $actual === $offset ? $guess : $wallSeconds - $actual;
    }
}
