<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `[p0, p1, ...]` as a pattern: element i of the value is matched with $elements[i].
 *
 * @api
 */
final readonly class ArrayPattern implements PatternInterface
{
    /**
     * @param non-empty-list<PatternInterface> $elements
     */
    public function __construct(public array $elements)
    {
    }
}
