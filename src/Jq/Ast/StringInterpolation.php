<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * A string literal containing `\(expr)` interpolations, optionally preceded by a format: `@base64 "x\(.a)"`.
 *
 * $parts alternates freely: a plain string part is literal text (already unescaped), a Node part is an
 * interpolated expression whose every output is converted with the format (default: tostring) and
 * spliced in; multiple outputs yield the cartesian product, as jq does. A string without interpolation
 * is a {@see Literal}, and a bare `@format` is a {@see Format}.
 *
 * @api
 */
final readonly class StringInterpolation implements Node
{
    /**
     * @param ?string           $format format name without the `@`, or null for plain tostring
     * @param list<string|Node> $parts
     */
    public function __construct(
        public ?string $format,
        public array $parts,
    ) {
    }
}
