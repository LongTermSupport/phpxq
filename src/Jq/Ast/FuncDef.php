<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * `def name(params): body;`.
 *
 * A parameter is stored as written without punctuation, except that a value parameter keeps its `$`
 * prefix: `def f(g; $x): ...` has params ['g', '$x']. A `$x` parameter is sugar for a closure parameter x
 * plus `x as $x |` around the body; the compiler performs that expansion.
 *
 * @api
 */
final readonly class FuncDef
{
    /**
     * @param list<string> $params
     */
    public function __construct(
        public string $name,
        public array $params,
        public NodeInterface $body,
        public int $line = 1,
    ) {
    }

    public function arity(): int
    {
        return \count($this->params);
    }

    public function signature(): string
    {
        return $this->name . '/' . $this->arity();
    }
}
