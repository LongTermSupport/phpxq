<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * A parsed jq source file: the main program or a library module.
 *
 * Top-level `def`s are collected in $defs; $body is the expression after them. A library module has a
 * null body. A main program with a null body (empty program, or defs only) means identity.
 *
 * @api
 */
final readonly class Program
{
    /**
     * @param list<ImportDirective> $imports
     * @param list<FuncDef>         $defs
     */
    public function __construct(
        public array $imports,
        public ?ModuleDirective $module,
        public array $defs,
        public ?NodeInterface $body,
    ) {
    }
}
