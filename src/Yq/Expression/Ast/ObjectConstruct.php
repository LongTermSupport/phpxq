<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression\Ast;

use LTS\PhpXq\Yq\Expression\ExpressionNodeInterface;

/**
 * `{ k1: v1, k2: v2 }`: builds a mapping; one result per combination when a key or value yields several.
 */
final readonly class ObjectConstruct implements ExpressionNodeInterface
{
    /**
     * @param list<ObjectEntry> $entries
     */
    public function __construct(
        public array $entries = [],
    ) {
    }
}
