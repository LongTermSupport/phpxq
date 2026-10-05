<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Ast\Bind;
use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\FunctionCall;
use LTS\PhpXq\Jq\Ast\ImportDirective;
use LTS\PhpXq\Jq\Ast\ImportKindEnum;
use LTS\PhpXq\Jq\Ast\NodeInterface;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Ast\VariablePattern;
use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Jq\Runtime\ModuleLoaderInterface;
use LTS\PhpXq\Json\JsonObject;

/**
 * State shared by every compilation of one compiler: the builtins, the lazily parsed prelude, the module
 * cache and the {@see RunState} the compiled ops talk to.
 *
 * @internal
 */
final class Core
{
    public readonly RunState $state;

    /** @var list<string> */
    private array $globalNames = [];

    private ?DefSet $prelude = null;

    /** @var array<string, DefSet> */
    private array $modules = [];

    /** @var list<string> */
    private array $loading = [];

    public function __construct(
        public readonly BuiltinRegistryInterface $builtins,
        private readonly ParserInterface $parser,
        public readonly ModuleLoaderInterface $loader,
    ) {
        $this->state = new RunState();
    }

    /**
     * Compile a main program to the op that runs its body.
     *
     * @throws JqCompileException
     */
    public function compileMain(Program $program, string ...$globalNames): OpInterface
    {
        $this->globalNames = array_values($globalNames);
        $set               = $this->buildSet($program, null);
        foreach ($set->functions() as $function) {
            $this->ensureCompiled($function);
        }

        if (!$program->body instanceof NodeInterface) {
            return new IdentityOp();
        }

        return new NodeCompiler($this, $set, \PHP_INT_MAX)->compile($program->body, null);
    }

    public function isGlobal(string $name): bool
    {
        return ReservedGlobalEnum::tryFrom($name) instanceof ReservedGlobalEnum || \in_array($name, $this->globalNames, true);
    }

    /**
     * Compile the body of a top-level function if that has not happened yet.
     *
     * @throws JqCompileException
     */
    public function ensureCompiled(FuncInfo $function): void
    {
        if ($function->op instanceof OpInterface || $function->compiling || !$function->home instanceof DefSet) {
            return;
        }

        $function->compiling = true;
        try {
            [$names, $body] = self::expand($function->definition());
            $scope          = null;
            foreach ($names as $name) {
                $scope = Scope::param($scope, $name);
            }

            $function->op = new NodeCompiler($this, $function->home, $function->limit)->compile($body, $scope);
        } finally {
            $function->compiling = false;
        }
    }

    /**
     * Closure parameter names and the body of a definition with `$name` parameters expanded to
     * `name as $name | ...` bindings (the first parameter outermost).
     *
     * @return array{list<string>, NodeInterface}
     */
    public static function expand(FuncDef $definition): array
    {
        $names  = [];
        $values = [];
        foreach ($definition->params as $parameter) {
            if (str_starts_with($parameter, '$')) {
                $parameter = substr($parameter, 1);
                $values[]  = $parameter;
            }

            $names[] = $parameter;
        }

        $body = $definition->body;
        foreach (array_reverse($values) as $name) {
            $body = new Bind(new FunctionCall($name, [], $definition->line), [new VariablePattern($name)], $body);
        }

        return [$names, $body];
    }

    /**
     * @throws JqCompileException
     */
    private function prelude(): DefSet
    {
        if ($this->prelude instanceof DefSet) {
            return $this->prelude;
        }

        $set           = new DefSet();
        $this->prelude = $set;
        $parser        = $this->parser;
        // Each top-level `def` starts in column 0 and its continuation lines are indented, so the prelude
        // splits into one chunk per definition, parsed only when something calls it (start-up cost: parsing
        // the whole prelude and loading its AST classes on every run).
        $chunks = preg_split('/^(?=def )/m', $this->builtins->prelude(), -1, \PREG_SPLIT_NO_EMPTY);
        foreach (false === $chunks ? [] : $chunks as $chunk) {
            if (1 === preg_match('/;\s*def\s/', $chunk) || 1 !== preg_match('/^def ([A-Za-z_][A-Za-z_0-9]*)(?:\(([^)]*)\))?:/', $chunk, $head)) {
                // several definitions in one chunk, or an unusual head: parse it now, like the whole text
                foreach ($parser->parse($chunk)->defs as $definition) {
                    $set->add(new FuncInfo($definition, $set, $set->size() + 1, true));
                }

                continue;
            }

            $arity = isset($head[2]) && '' !== $head[2] ? substr_count($head[2], ';') + 1 : 0;
            $set->add(FuncInfo::deferred(
                $head[1] . '/' . $arity,
                static fn (): FuncDef => $parser->parse($chunk)->defs[0],
                $set,
                $set->size() + 1,
            ));
        }

        return $set;
    }

    /**
     * The definition set of a source file: its imports resolved and its top-level defs registered.
     *
     * @throws JqCompileException
     */
    private function buildSet(Program $program, ?string $path): DefSet
    {
        $set = new DefSet($this->prelude());
        foreach ($program->imports as $import) {
            $search = $import->metadata instanceof JsonObject ? $import->metadata->get('search') : null;
            if (ImportKindEnum::Data === $import->kind) {
                $set->addData((string)$import->alias, ...$this->loader->loadData($import->path, $search, $path));

                continue;
            }

            $module = $this->module($import, $search, $path);
            if (ImportKindEnum::Include === $import->kind) {
                $set->include($module);
            } else {
                $set->alias((string)$import->alias, $module);
            }
        }

        foreach ($program->defs as $definition) {
            $set->add(new FuncInfo($definition, $set, $set->size() + 1));
        }

        return $set;
    }

    /**
     * @throws JqCompileException
     */
    private function module(ImportDirective $import, mixed $search, ?string $importer): DefSet
    {
        $loaded = $this->loader->loadLibrary($import->path, $search, $importer);
        if (isset($this->modules[$loaded->path])) {
            return $this->modules[$loaded->path];
        }

        if (\in_array($loaded->path, $this->loading, true)) {
            throw new JqCompileException(\sprintf('circular import of module %s (%s)', $import->path, $loaded->path));
        }

        $this->loading[] = $loaded->path;
        try {
            $set = $this->buildSet($loaded->program, $loaded->path);
            foreach ($set->functions() as $function) {
                $this->ensureCompiled($function);
            }
        } finally {
            array_pop($this->loading);
        }

        return $this->modules[$loaded->path] = $set;
    }
}
