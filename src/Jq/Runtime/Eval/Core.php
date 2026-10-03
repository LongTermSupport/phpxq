<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Ast\Bind;
use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\FunctionCall;
use LTS\PhpXq\Jq\Ast\ImportDirective;
use LTS\PhpXq\Jq\Ast\ImportKind;
use LTS\PhpXq\Jq\Ast\Node;
use LTS\PhpXq\Jq\Ast\Program;
use LTS\PhpXq\Jq\Ast\VariablePattern;
use LTS\PhpXq\Jq\Parser\ParserInterface;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistry;
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
        public readonly BuiltinRegistry $builtins,
        private readonly ParserInterface $parser,
        public readonly ModuleLoaderInterface $loader,
    ) {
        $this->state = new RunState();
    }

    /**
     * Compile a main program to the op that runs its body.
     *
     * @param list<string> $globalNames
     *
     * @throws JqCompileException
     */
    public function compileMain(Program $program, array $globalNames): Op
    {
        $this->globalNames = $globalNames;
        $set               = $this->buildSet($program, null);
        foreach ($set->functions() as $function) {
            $this->ensureCompiled($function);
        }

        if (!$program->body instanceof Node) {
            return new IdentityOp();
        }

        return new NodeCompiler($this, $set, \PHP_INT_MAX)->compile($program->body, null);
    }

    public function isGlobal(string $name): bool
    {
        return 'ENV' === $name || '__prog_args' === $name || \in_array($name, $this->globalNames, true);
    }

    /**
     * Compile the body of a top-level function if that has not happened yet.
     *
     * @throws JqCompileException
     */
    public function ensureCompiled(FuncInfo $function): void
    {
        if ($function->op instanceof Op || $function->compiling || !$function->home instanceof DefSet) {
            return;
        }

        $function->compiling = true;
        try {
            [$names, $body] = self::expand($function->definition);
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
     * @return array{list<string>, Node}
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
        $source        = $this->builtins->prelude();
        if ('' !== trim($source)) {
            foreach ($this->parser->parse($source)->defs as $definition) {
                $set->add(new FuncInfo($definition, $set, $set->size() + 1, true));
            }
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
            if (ImportKind::Data === $import->kind) {
                $set->addData((string)$import->alias, $this->loader->loadData($import->path, $search, $path));

                continue;
            }

            $module = $this->module($import, $search, $path);
            if (ImportKind::Include === $import->kind) {
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
            throw new JqCompileException(\sprintf('module %s imports itself through a cycle (%s)', $import->path, $loaded->path));
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
