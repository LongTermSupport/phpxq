<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

/**
 * A number whose canonical literal cannot be recovered from its IEEE double, for example 13911860366432393
 * or 1E+1000. It exists only so an unchanged literal can be printed back exactly; every arithmetic or
 * builtin operation consumes {@see self::$value} and so truncates to double, as jq does.
 *
 * @api
 */
final readonly class PreciseNumber
{
    public function __construct(
        public float $value,
        public string $literal,
    ) {
    }
}
