<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `[body]` collects every output of $body; `[]` has a null body.
 *
 * @api
 */
final readonly class ArrayConstruct implements NodeInterface
{
    public function __construct(public ?NodeInterface $body)
    {
    }
}
