<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * One `key: value` pair of an object construction. The parser desugars every shorthand so that both
 * sides are always present: `{a}` is key Literal "a" with value Index(Identity, "a"); `{$x}` is key
 * Literal "x" with value Variable x; `{"a b"}` and `{"a\(1)"}` use the string node as key and
 * Index(Identity, key) as value; `{(e): v}` uses the parenthesised node as key; `{@base64 "x"}` likewise.
 * An unquoted keyword key such as `{if: 1}` is a string Literal.
 *
 * @api
 */
final readonly class ObjectEntry
{
    public function __construct(
        public Node $key,
        public Node $value,
    ) {
    }
}
