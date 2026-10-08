<?php

declare(strict_types=1);

namespace LTS\PhpXq\Limits;

/**
 * Bounds on how far one operation may grow a value, each refused with an ordinary, catchable error:
 *
 * - an array index above jq 1.6's own bound, MAX_ARRAY_INDEX (jq only);
 * - padding a sequence with more than MAX_PADDING (2^28) new entries to reach a far index;
 * - padding whose estimated cost exceeds what the memory_limit leaves, when one is set. A jq null is a 16-byte
 *   slot in an array that doubles as it grows and is reallocated, so JQ_PADDED_ENTRY_BYTES allows 48; a yq null
 *   is a node object, measured near 420 bytes, so YQ_PADDED_ENTRY_BYTES allows 512;
 * - repeating a string in jq into more than MAX_STRING_BYTES.
 *
 * These are not a guarantee against running out of memory, which stays a fatal error neither jq's `try` nor yq
 * can catch: padding within them can still exhaust memory the estimate did not foresee, other operations are
 * not checked, and without a memory_limit only the fixed bounds apply.
 *
 * @api
 */
final readonly class AllocationLimit
{
    public const int MAX_ARRAY_INDEX = 536870911;

    public const int MAX_PADDING = 268435456;

    public const int MAX_STRING_BYTES = 1073741824;

    public const int JQ_PADDED_ENTRY_BYTES = 48;

    public const int YQ_PADDED_ENTRY_BYTES = 512;

    public const string PADDING_ERROR = 'cannot pad a sequence to index %d: more than %d new entries';

    public const string PADDING_MEMORY_ERROR = 'cannot pad a sequence to index %d: %d new entries would not fit in the memory_limit';

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

    /**
     * Whether $entries new entries at an estimated $bytesPerEntry each need more memory than the memory_limit
     * leaves. Without a memory_limit nothing is out of reach.
     */
    public static function exceedsMemoryLimit(int $entries, int $bytesPerEntry): bool
    {
        $limit = ini_parse_quantity(\ini_get('memory_limit'));
        if ($limit <= 0) {
            return false;
        }

        return $entries > intdiv(max(0, $limit - memory_get_usage()), $bytesPerEntry);
    }

    /**
     * The yq error for padding a sequence of $count entries up to $index, or null when the padding is allowed.
     */
    public static function sequencePaddingError(int $index, int $count): ?string
    {
        if (self::padsTooFar($index, $count)) {
            return self::paddingError($index);
        }

        if (self::exceedsMemoryLimit($index - $count, self::YQ_PADDED_ENTRY_BYTES)) {
            return \sprintf(self::PADDING_MEMORY_ERROR, $index, $index - $count);
        }

        return null;
    }
}
