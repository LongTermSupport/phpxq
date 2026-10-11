<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * A constant in the value model: null, true, false, or a string without interpolation. Numbers are
 * {@see NumberLiteral}.
 *
 * @internal
 */
final readonly class Literal implements NodeInterface
{
    public function __construct(public mixed $value)
    {
    }
}
