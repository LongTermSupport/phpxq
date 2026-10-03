<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use LogicException;

/**
 * Number literal text to the value model, shared by the JSON decoder and the jq compiler so that the
 * literal-preservation rules (architecture.md, number semantics) are defined once. OWNER: JSON codec
 * worker. Skeleton only: keep this signature when implementing.
 *
 * @api
 */
final class NumberParser
{
    private function __construct()
    {
    }

    /**
     * @param string $literal a JSON/jq number literal, e.g. `1`, `-0`, `1.5e3`, `13911860366432393`
     *
     * @return int|float|PreciseNumber int when integral and |n| <= 2^53; float when the double prints
     *                                 identically to the literal; PreciseNumber otherwise
     */
    public static function parse(string $literal): int|float|PreciseNumber
    {
        throw new LogicException('NumberParser::parse is not implemented for ' . $literal);
    }
}
