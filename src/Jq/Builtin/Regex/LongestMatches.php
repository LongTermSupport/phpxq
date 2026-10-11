<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * The `l` modifier's searches over one subject. The match anchored at a start position does not depend on
 * where the search began, so every start position is tried once, up front, and the longest match at or after
 * each position (the earliest one on a tie) is kept. A search from an offset is then a lookup, and a global
 * search costs one pass instead of one pass per match.
 *
 * $starts lists the start positions that match, $widths their match widths in bytes, and $best[$i] the index
 * into both of the longest match at or after $starts[$i]. $cursor is the first index a later search can use:
 * the offsets jq searches from only grow.
 *
 * @internal
 */
final class LongestMatches
{
    private const int FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    private readonly string $anchored;

    /** @var list<int> */
    private array $starts = [];

    /** @var list<int> */
    private array $widths = [];

    /** @var array<int, int> */
    private array $best = [];

    private int $cursor = 0;

    /**
     * @throws JqException when PCRE fails at match time
     */
    public function __construct(OnigRegex $regex, private readonly string $subject, bool $ascii)
    {
        $this->anchored = $regex->anchoredPcre($ascii);
        $length         = \strlen($subject);
        for ($position = 0; $position <= $length; $position += CodepointCursor::characterWidth($subject, $position)) {
            $groups = $this->at($position);
            if (null !== $groups) {
                $this->starts[] = $position;
                $this->widths[] = \strlen((string)$groups[0][0]);
            }
        }

        $longest = null;
        for ($index = \count($this->starts) - 1; $index >= 0; --$index) {
            if (null === $longest || $this->widths[$index] >= $this->widths[$longest]) {
                $longest = $index;
            }

            $this->best[$index] = $longest;
        }
    }

    /**
     * The longest match starting at or after $offset, as PCRE's offset-capture groups, or null when none.
     * $offset must not be lower than the offset of an earlier call.
     *
     * @return ?array<array{0: ?string, 1: int}>
     *
     * @throws JqException when PCRE fails at match time
     */
    public function from(int $offset): ?array
    {
        $count = \count($this->starts);
        while ($this->cursor < $count && $this->starts[$this->cursor] < $offset) {
            ++$this->cursor;
        }

        if ($this->cursor === $count) {
            return null;
        }

        return $this->at($this->starts[$this->best[$this->cursor]]);
    }

    /**
     * @return ?array<array{0: ?string, 1: int}>
     *
     * @throws JqException when PCRE fails at match time
     */
    private function at(int $position): ?array
    {
        $found = preg_match($this->anchored, $this->subject, $groups, self::FLAGS, $position);
        if (false === $found) {
            throw new JqException(preg_last_error_msg());
        }

        return 1 === $found ? $groups : null;
    }
}
