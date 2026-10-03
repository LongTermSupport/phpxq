<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LogicException;

/**
 * A user or prelude function definition: its AST, where its body resolves names, and the compiled body once
 * available. The body is filled in after construction so that a function can call itself.
 *
 * @internal
 */
final class FuncInfo
{
    public ?Op $op = null;

    public bool $compiling = false;

    /**
     * @param ?DefSet $home  the definition set a top-level function resolves names in, null for a nested one
     * @param int     $limit top-level functions only see definitions below this index in $home
     */
    public function __construct(
        public readonly FuncDef $definition,
        public readonly ?DefSet $home,
        public readonly int $limit,
        public readonly bool $prelude = false,
    ) {
    }

    public function body(): Op
    {
        return $this->op ?? throw new LogicException('Function ' . $this->definition->signature() . ' is not compiled yet');
    }
}
