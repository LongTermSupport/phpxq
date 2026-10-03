<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * One entry of an object pattern.
 *
 * - `$name`          variable "name", key null (the key is "name"), value null
 * - `$name: pattern` variable "name", key null, value pattern (binds $name to the member too)
 * - `key: pattern`   variable null, key a string Literal / string node / parenthesised expression, value pattern
 *
 * The key node is evaluated against the pattern's input value, may generate several keys, and must
 * yield strings.
 *
 * @api
 */
final readonly class ObjectPatternEntry
{
    public function __construct(
        public ?string $variable,
        public ?Node $key,
        public ?Pattern $value,
    ) {
    }
}
