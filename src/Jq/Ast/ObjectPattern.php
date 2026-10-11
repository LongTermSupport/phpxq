<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `{...}` as a pattern.
 *
 * @internal
 */
final readonly class ObjectPattern implements PatternInterface
{
    /**
     * @param non-empty-list<ObjectPatternEntry> $entries
     */
    public function __construct(public array $entries)
    {
    }
}
