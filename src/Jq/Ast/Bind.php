<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `source as pattern | body`, or with destructuring alternatives `source as pat1 ?// pat2 | body`.
 * For each output of $source the body runs once per source output with the pattern's variables bound.
 * With several $patterns every variable of every alternative is bound (null when absent from the
 * matching alternative), and an error in the body moves on to the next alternative, as jq does.
 *
 * @internal
 */
final readonly class Bind implements NodeInterface
{
    /**
     * @param non-empty-list<PatternInterface> $patterns
     */
    public function __construct(
        public NodeInterface $source,
        public array $patterns,
        public NodeInterface $body,
    ) {
    }
}
