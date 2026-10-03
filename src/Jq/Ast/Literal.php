<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * A constant in the value model: null, true, false, or a string without interpolation. Numbers are
 * {@see NumberLiteral}.
 *
 * @api
 */
final readonly class Literal implements Node
{
    public function __construct(public mixed $value)
    {
    }
}
