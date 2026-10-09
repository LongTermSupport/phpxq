<?php

declare(strict_types=1);

namespace LTS\PhpXq\Limits;

/**
 * Bounds on how far one operation may grow a value, each refused with an ordinary, catchable error:
 *
 * - an array index above jq 1.6's own bound, MAX_ARRAY_INDEX (jq only);
 * - padding a sequence with more than MAX_PADDING (2^28) new entries to reach a far index;
 * - repeating a string in jq into more than MAX_STRING_BYTES.
 *
 * These limits do not prevent running out of memory, which stays a fatal error neither jq's `try` nor yq can
 * catch: padding or repetition within them can still exhaust the memory_limit or the host, and other
 * operations are not checked at all.
 *
 * @api
 */
final readonly class AllocationLimit
{
    public const int MAX_ARRAY_INDEX = 536870911;

    public const int MAX_PADDING = 268435456;

    public const int MAX_STRING_BYTES = 1073741824;

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
