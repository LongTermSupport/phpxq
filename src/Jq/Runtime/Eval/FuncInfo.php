<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LogicException;
use LTS\PhpXq\Jq\Ast\FuncDef;

/**
 * A user or prelude function definition: its AST, where its body resolves names, and the compiled body once
 * available. The body is filled in after construction so that a function can call itself.
 *
 * A prelude function is created with {@see self::deferred()}: its `name/arity` is known from the source text,
 * its AST is parsed the first time something needs it, so a run pays for the few prelude functions it calls and
 * not for all of them.
 *
 * @internal
 */
final class FuncInfo
{
    public ?OpInterface $op = null;

    public bool $compiling = false;

    /**
     * @param ?DefSet             $home     the definition set a top-level function resolves names in, null for a nested one
     * @param int                 $limit    top-level functions only see definitions below this index in $home
     * @param ?Closure(): FuncDef $deferred parses the definition on first use; null when $definition is given
     */
    public function __construct(
        private ?FuncDef $parsed,
        public readonly ?DefSet $home,
        public readonly int $limit,
        public readonly bool $prelude = false,
        public readonly string $signature = '',
        private readonly ?Closure $deferred = null,
    ) {
        if (!$this->parsed instanceof FuncDef && !$deferred instanceof Closure) {
            throw new LogicException('A function needs a definition or a way to parse it');
        }
    }

    /**
     * @param Closure(): FuncDef $parse
     */
    public static function deferred(string $signature, Closure $parse, DefSet $home, int $limit): self
    {
        return new self(null, $home, $limit, true, $signature, $parse);
    }

    public function definition(): FuncDef
    {
        return $this->parsed ??= ($this->deferred ?? throw new LogicException('Function ' . $this->signature . ' has no definition'))();
    }

    public function signature(): string
    {
        return '' !== $this->signature ? $this->signature : $this->definition()->signature();
    }

    public function body(): OpInterface
    {
        return $this->op ?? throw new LogicException('Function ' . $this->signature() . ' is not compiled yet');
    }
}
