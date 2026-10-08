<?php

declare(strict_types=1);

namespace LTS\PhpXq\Limits;

/**
 * Bounds on what one small expression or input may make phpxq allocate. Running out of memory is a fatal
 * error that neither jq's `try` nor yq can catch, so an operation that would grow a value past these limits
 * fails with an ordinary error first.
 *
 * @api
 */
final readonly class AllocationLimit
{
    /** jq's own limit on an array index: larger ones are an error even when nothing needs padding. */
    public const int MAX_ARRAY_INDEX = 536870911;

    /**
     * The most null elements one assignment may add to reach an index past the end of an array: 2^28, far beyond
     * any padding jq 1.6 or Go yq is used for, and still short of what a PHP array that size would need.
     */
    public const int MAX_PADDING = 268435456;

    /** The longest string jq's string repetition (`"ab" * n`) may build: 1 GiB, beyond what jq 1.6 builds. */
    public const int MAX_STRING_BYTES = 1073741824;

    /** The yq error text for padding past {@see self::MAX_PADDING}; jq words its own. */
    public const string PADDING_ERROR = 'cannot pad a sequence to index %d: more than %d new entries';

    private function __construct()
    {
    }

    /**
     * Whether reaching the index of a sequence that has $count entries pads it past {@see self::MAX_PADDING}.
     */
    public static function padsTooFar(int $index, int $count): bool
    {
        return $index - $count > self::MAX_PADDING;
    }

    public static function paddingError(int $index): string
    {
        return \sprintf(self::PADDING_ERROR, $index, self::MAX_PADDING);
    }
}
