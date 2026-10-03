<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `{k: v, ...}`. Every key and value expression may be a generator; the result is the cartesian
 * product, with the first entry varying slowest, as jq does.
 *
 * @api
 */
final readonly class ObjectConstruct implements Node
{
    /**
     * @param list<ObjectEntry> $entries
     */
    public function __construct(public array $entries)
    {
    }
}
