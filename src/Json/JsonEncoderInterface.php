<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

/**
 * Value model to JSON text, byte-for-byte as jq prints it.
 *
 * Numbers: a {@see PreciseNumber} prints its canonical literal; int prints as an integer; a float with an
 * integral value prints without a fraction; other floats print as the shortest round-trip digits laid out
 * by jq 1.8's rules (see architecture.md, number semantics). nan prints null, infinities print as the
 * largest finite double. The encoder never emits a trailing newline.
 *
 * @api
 */
interface JsonEncoderInterface
{
    public function encode(mixed $value, EncodeOptions $options): string;
}
