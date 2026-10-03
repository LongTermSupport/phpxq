<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use Generator;

/**
 * JSON text to the value model.
 *
 * Numbers decode to int when integral and exactly representable as a double (|n| <= 2^53), to float when
 * not integral, and to {@see PreciseNumber} when the literal would not survive a double round trip.
 * Objects decode to {@see JsonObject}, arrays to PHP lists. Duplicate object keys: the last one wins and
 * keeps the position of the first, as jq does. Also accepted, as jq does: the bare words nan and NaN
 * (decoding to NAN), and invalid UTF-8 replaced by U+FFFD.
 *
 * @api
 */
interface JsonDecoderInterface
{
    /**
     * Decode exactly one JSON value (the `fromjson` contract); surrounding whitespace is allowed.
     *
     * @throws JsonSyntaxException
     */
    public function decodeOne(string $text): mixed;

    /**
     * Lazily decode a whitespace-separated sequence of values (the CLI input contract). Values before a
     * syntax error are yielded first; the exception is thrown when the generator reaches the bad text.
     *
     * @param bool $seq RFC 7464 mode (--seq): values are introduced by the RS character (0x1E)
     *
     * @return Generator<int, mixed>
     *
     * @throws JsonSyntaxException
     */
    public function decodeAll(string $text, bool $seq = false): Generator;
}
