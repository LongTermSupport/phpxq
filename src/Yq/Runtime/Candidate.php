<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;

/**
 * One match flowing through the evaluator: the node plus where it was found. yq operators such as
 * `path`, `key`, `parent`, `document_index`, `filename`, `line`, and every assignment need that
 * location, so a match is never a bare node.
 *
 * `parent` and `key` are null for a root. `key` is the key scalar of a mapping entry (the actual node in
 * the mapping, so `key` comments are reachable) or a !!int scalar index for a sequence item.
 */
final readonly class Candidate
{
    public function __construct(
        public Node $node,
        public ?self $parent = null,
        public ?Node $key = null,
        public int $documentIndex = 0,
        public int $fileIndex = 0,
        public string $filename = '',
    ) {
    }
}
