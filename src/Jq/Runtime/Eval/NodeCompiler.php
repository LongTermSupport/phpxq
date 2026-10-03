<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Ast\ArrayConstruct;
use LTS\PhpXq\Jq\Ast\ArrayPattern;
use LTS\PhpXq\Jq\Ast\Assign;
use LTS\PhpXq\Jq\Ast\AssignOp;
use LTS\PhpXq\Jq\Ast\Binary;
use LTS\PhpXq\Jq\Ast\BinaryOp;
use LTS\PhpXq\Jq\Ast\Bind;
use LTS\PhpXq\Jq\Ast\BreakOut;
use LTS\PhpXq\Jq\Ast\Comma;
use LTS\PhpXq\Jq\Ast\ForeachLoop;
use LTS\PhpXq\Jq\Ast\Format;
use LTS\PhpXq\Jq\Ast\FuncDefScope;
use LTS\PhpXq\Jq\Ast\FunctionCall;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Ast\IfThenElse;
use LTS\PhpXq\Jq\Ast\Index;
use LTS\PhpXq\Jq\Ast\Iterate;
use LTS\PhpXq\Jq\Ast\Label;
use LTS\PhpXq\Jq\Ast\Literal;
use LTS\PhpXq\Jq\Ast\Location;
use LTS\PhpXq\Jq\Ast\Negate;
use LTS\PhpXq\Jq\Ast\Node;
use LTS\PhpXq\Jq\Ast\NumberLiteral;
use LTS\PhpXq\Jq\Ast\ObjectConstruct;
use LTS\PhpXq\Jq\Ast\ObjectPattern;
use LTS\PhpXq\Jq\Ast\Pattern;
use LTS\PhpXq\Jq\Ast\Pipe;
use LTS\PhpXq\Jq\Ast\Reduce;
use LTS\PhpXq\Jq\Ast\Slice;
use LTS\PhpXq\Jq\Ast\StringInterpolation;
use LTS\PhpXq\Jq\Ast\TryCatch;
use LTS\PhpXq\Jq\Ast\Variable;
use LTS\PhpXq\Jq\Ast\VariablePattern;
use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Jq\Runtime\StreamBuiltin;
use LTS\PhpXq\Jq\Runtime\ValueBuiltin;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\NumberParser;
use LTS\PhpXq\Json\Values;
use LogicException;

/**
 * Translates AST nodes to {@see Op}s inside one name-resolution context: the {@see DefSet} the enclosing
 * top-level definition belongs to and the lexical {@see Scope} that mirrors the run-time environment.
 *
 * @internal
 */
final class NodeCompiler
{
    private const array FORMATS = ['text', 'json', 'html', 'uri', 'csv', 'tsv', 'sh', 'base64', 'base64d', 'base32', 'base32d'];

    public function __construct(
        private readonly Core $core,
        private readonly ?DefSet $home,
        private readonly int $limit,
    ) {
    }

    /**
     * @throws JqCompileException
     */
    public function compile(Node $node, ?Scope $scope): Op
    {
        return match (true) {
            $node instanceof Identity            => new IdentityOp(),
            $node instanceof Literal             => new ConstOp($node->value),
            $node instanceof NumberLiteral       => new ConstOp(NumberParser::parse($node->text)),
            $node instanceof Pipe                => $this->pipe($node, $scope),
            $node instanceof Index               => $this->index($node, $scope),
            $node instanceof FunctionCall        => $this->call($node, $scope),
            $node instanceof Variable            => $this->variable($node, $scope),
            $node instanceof Binary              => $this->binary($node, $scope),
            $node instanceof Comma               => new CommaOp($this->compile($node->left, $scope), $this->compile($node->right, $scope)),
            $node instanceof Iterate             => new IterateOp($node->target instanceof Identity ? null : $this->compile($node->target, $scope)),
            $node instanceof Slice               => new SliceOp(
                $this->compile($node->target, $scope),
                null === $node->from ? null : $this->compile($node->from, $scope),
                null === $node->to ? null : $this->compile($node->to, $scope),
            ),
            $node instanceof IfThenElse          => $this->conditional($node, $scope),
            $node instanceof TryCatch            => new TryOp(
                $this->compile($node->body, $scope),
                null === $node->handler ? null : $this->compile($node->handler, $scope),
            ),
            $node instanceof ArrayConstruct      => new ArrayOp(null === $node->body ? null : $this->compile($node->body, $scope)),
            $node instanceof ObjectConstruct     => $this->object($node, $scope),
            $node instanceof Negate              => $this->negate($node, $scope),
            $node instanceof Assign              => $this->assign($node, $scope),
            $node instanceof Bind                => $this->bind($node, $scope),
            $node instanceof Reduce              => $this->reduce($node, $scope),
            $node instanceof ForeachLoop         => $this->foreach($node, $scope),
            $node instanceof Label               => new LabelOp($this->compile($node->body, Scope::label($scope, $node->name))),
            $node instanceof BreakOut            => $this->breakOut($node, $scope),
            $node instanceof FuncDefScope        => $this->nestedDefinition($node, $scope),
            $node instanceof StringInterpolation => $this->interpolation($node, $scope),
            $node instanceof Format              => new FormatOp($this->formatter($node->name)),
            $node instanceof Location            => new ConstOp(new JsonObject(['file' => $node->file, 'line' => $node->line])),
            default                              => throw new LogicException('Unsupported AST node ' . $node::class),
        };
    }

    private function pipe(Pipe $node, ?Scope $scope): Op
    {
        $left  = $this->compile($node->left, $scope);
        $right = $this->compile($node->right, $scope);
        if ($left instanceof SingleOp && $right instanceof SingleOp) {
            return new SinglePipeOp($left, $right);
        }

        if ($left instanceof SingleOp) {
            return new ValuePipeOp($left, $right);
        }

        return new PipeOp($left, $right);
    }

    private function index(Index $node, ?Scope $scope): Op
    {
        $index = $this->compile($node->index, $scope);
        if ($node->target instanceof Identity && $index instanceof ConstOp && \is_string($index->constant)) {
            return new FieldOp($index->constant);
        }

        $target = $this->compile($node->target, $scope);
        if ($target instanceof SingleOp && $index instanceof SingleOp) {
            return new SingleIndexOp($target, $index);
        }

        return new IndexOp($target, $index);
    }

    private function negate(Negate $node, ?Scope $scope): Op
    {
        $operand = $this->compile($node->operand, $scope);

        return $operand instanceof SingleOp ? new SingleNegateOp($operand) : new NegateOp($operand);
    }

    private function conditional(IfThenElse $node, ?Scope $scope): Op
    {
        $condition = $this->compile($node->condition, $scope);
        $then      = $this->compile($node->then, $scope);
        $else      = null === $node->else ? null : $this->compile($node->else, $scope);
        if ($condition instanceof SingleOp && $then instanceof SingleOp && (null === $else || $else instanceof SingleOp)) {
            return new SingleIfOp($condition, $then, $else);
        }

        return new IfOp($condition, $then, $else);
    }

    private function binary(Binary $node, ?Scope $scope): Op
    {
        $left  = $this->compile($node->left, $scope);
        $right = $this->compile($node->right, $scope);
        if (BinaryOp::Alt === $node->op) {
            return new AltOp($left, $right);
        }

        if (BinaryOp::And === $node->op || BinaryOp::Or === $node->op) {
            $isAnd = BinaryOp::And === $node->op;

            return $left instanceof SingleOp && $right instanceof SingleOp
                ? new SingleLogicOp($left, $right, $isAnd)
                : new LogicOp($left, $right, $isAnd);
        }

        $operation = self::operation($node->op);

        return $left instanceof SingleOp && $right instanceof SingleOp
            ? new SingleBinaryOp($left, $right, $operation)
            : new BinaryOp($left, $right, $operation);
    }

    /**
     * @return Closure(mixed, mixed): mixed
     */
    private static function operation(BinaryOp $operator): Closure
    {
        return match ($operator) {
            BinaryOp::Add => Arithmetic::add(...),
            BinaryOp::Sub => Arithmetic::subtract(...),
            BinaryOp::Mul => Arithmetic::multiply(...),
            BinaryOp::Div => Arithmetic::divide(...),
            BinaryOp::Mod => Arithmetic::modulo(...),
            BinaryOp::Eq  => Cmp::eq(...),
            BinaryOp::Neq => Cmp::ne(...),
            BinaryOp::Lt  => Cmp::lt(...),
            BinaryOp::Le  => Cmp::le(...),
            BinaryOp::Gt  => Cmp::gt(...),
            BinaryOp::Ge  => Cmp::ge(...),
            default       => throw new LogicException('Not an arithmetic operator: ' . $operator->value),
        };
    }

    private function assign(Assign $node, ?Scope $scope): Op
    {
        $left  = $this->compile($node->left, $scope);
        $right = $this->compile($node->right, $scope);

        return match ($node->op) {
            AssignOp::Set    => new SetAssignOp($left, $right),
            AssignOp::Update => new UpdateAssignOp($left, $right),
            AssignOp::Add    => new ArithAssignOp($left, $right, Arithmetic::add(...)),
            AssignOp::Sub    => new ArithAssignOp($left, $right, Arithmetic::subtract(...)),
            AssignOp::Mul    => new ArithAssignOp($left, $right, Arithmetic::multiply(...)),
            AssignOp::Div    => new ArithAssignOp($left, $right, Arithmetic::divide(...)),
            AssignOp::Mod    => new ArithAssignOp($left, $right, Arithmetic::modulo(...)),
            AssignOp::Alt    => new ArithAssignOp(
                $left,
                $right,
                static fn (mixed $old, mixed $operand): mixed => null !== $old && false !== $old ? $old : $operand,
            ),
        };
    }

    private function object(ObjectConstruct $node, ?Scope $scope): Op
    {
        $entries = [];
        $single  = true;
        foreach ($node->entries as $entry) {
            self::assertObjectKey($entry->key);
            $key   = $this->compile($entry->key, $scope);
            $value = $this->compile($entry->value, $scope);
            $single = $single && $key instanceof SingleOp && $value instanceof SingleOp;
            $entries[] = [$key, $value];
        }

        if ($single) {
            $singles = [];
            foreach ($entries as [$key, $value]) {
                \assert($key instanceof SingleOp && $value instanceof SingleOp);
                $singles[] = [$key, $value];
            }

            return new SingleObjectOp($singles);
        }

        return new ObjectOp($entries);
    }

    /**
     * A constant key that is not a string can never work: a compile error, as in jq.
     *
     * @throws JqCompileException
     */
    private static function assertObjectKey(Node $key): void
    {
        $constant = match (true) {
            $key instanceof NumberLiteral => NumberParser::parse($key->text),
            $key instanceof Literal       => $key->value,
            default                       => '',
        };
        if (!\is_string($constant)) {
            throw new JqCompileException(\sprintf(
                'Cannot use %s (%s) as object key at <top-level>, line 1:',
                Values::typeName($constant),
                ErrorText::dump($constant),
            ));
        }
    }

    private function breakOut(BreakOut $node, ?Scope $scope): Op
    {
        $depth = $scope?->depthOfLabel($node->label);
        if (null === $depth) {
            throw new JqCompileException(\sprintf('$*label-%s is not defined at <top-level>, line %d:', $node->label, $node->line));
        }

        return new BreakOp($depth);
    }

    private function nestedDefinition(FuncDefScope $node, ?Scope $scope): Op
    {
        $info      = new FuncInfo($node->def, null, 0);
        $withSelf  = Scope::func($scope, $info);
        [$names, $body] = Core::expand($node->def);
        $bodyScope = $withSelf;
        foreach ($names as $name) {
            $bodyScope = Scope::param($bodyScope, $name);
        }

        $info->op = $this->compile($body, $bodyScope);

        return new FuncDefOp($this->compile($node->rest, $withSelf));
    }

    private function variable(Variable $node, ?Scope $scope): Op
    {
        $name  = $node->name;
        $depth = $scope?->depthOfVariable($name);
        if (null !== $depth) {
            return new VarOp($depth);
        }

        $alias = str_contains($name, '::') ? substr($name, 0, (int)strpos($name, '::')) : $name;
        $data  = $this->home?->data($alias);
        if (null !== $data) {
            return new ConstOp($data);
        }

        if ($this->core->isGlobal($name)) {
            return new GlobalVarOp($this->core->state, $name);
        }

        throw new JqCompileException(\sprintf('$%s is not defined at <top-level>, line %d:', $name, $node->line));
    }

    private function call(FunctionCall $node, ?Scope $scope): Op
    {
        $name  = $node->name;
        $arity = $node->arity();
        $depth = 0;
        for ($entry = $scope; null !== $entry; $entry = $entry->parent) {
            if (ScopeKind::Param === $entry->kind && 0 === $arity && $entry->name === $name) {
                return new ParamCallOp($depth);
            }

            if (ScopeKind::Func === $entry->kind && $entry->arity === $arity && $entry->name === $name && null !== $entry->function) {
                return new CallOp($entry->function, $depth, $this->arguments($node, $scope));
            }

            ++$depth;
        }

        $function = $this->findFunction($name, $arity);
        if (null !== $function && !$function->prelude) {
            return $this->callTopLevel($function, $node, $scope);
        }

        $intrinsic = $this->intrinsic($node, $scope);
        if (null !== $intrinsic) {
            return $intrinsic;
        }

        if (null !== $function) {
            return $this->callTopLevel($function, $node, $scope);
        }

        $native = $this->core->builtins->lookup($name, $arity);
        if ($native instanceof ValueBuiltin) {
            $arguments = $this->arguments($node, $scope);
            if (self::allSingle($arguments)) {
                return new SingleNativeOp($native, $arguments, $this->core->state);
            }

            \assert([] !== $arguments);

            return new NativeValueOp($native, $arguments, $this->core->state);
        }

        if ($native instanceof StreamBuiltin) {
            return new NativeStreamOp($native, $this->arguments($node, $scope), $this->core->state);
        }

        throw new JqCompileException(\sprintf('%s/%d is not defined at <top-level>, line %d:', $name, $arity, $node->line));
    }

    private function findFunction(string $name, int $arity): ?FuncInfo
    {
        if (null === $this->home) {
            return null;
        }

        if (str_contains($name, '::')) {
            $split = (int)strrpos($name, '::');

            return $this->home->findAliased(substr($name, 0, $split), substr($name, $split + 2), $arity);
        }

        return $this->home->find($name, $arity, $this->limit);
    }

    private function callTopLevel(FuncInfo $function, FunctionCall $node, ?Scope $scope): Op
    {
        $this->core->ensureCompiled($function);

        return new CallOp($function, -1, $this->arguments($node, $scope));
    }

    /**
     * @return list<Op>
     */
    private function arguments(FunctionCall $node, ?Scope $scope): array
    {
        $arguments = [];
        foreach ($node->args as $argument) {
            $arguments[] = $this->compile($argument, $scope);
        }

        return $arguments;
    }

    /**
     * @param list<Op> $operations
     *
     * @phpstan-assert-if-true list<SingleOp> $operations
     */
    private static function allSingle(array $operations): bool
    {
        foreach ($operations as $operation) {
            if (!$operation instanceof SingleOp) {
                return false;
            }
        }

        return true;
    }

    private function intrinsic(FunctionCall $node, ?Scope $scope): ?Op
    {
        return match ($node->signature()) {
            'empty/0'           => new EmptyOp(),
            'error/0'           => new ErrorOp(null),
            'error/1'           => new ErrorOp($this->compile($node->args[0], $scope)),
            'not/0'             => new NotOp(),
            'select/1'          => new SelectOp($this->compile($node->args[0], $scope)),
            'path/1'            => new PathOp($this->compile($node->args[0], $scope)),
            'getpath/1'         => new GetPathOp($this->compile($node->args[0], $scope)),
            'recurse/0'         => new RecurseOp(),
            'modulemeta/0'      => new ModuleMetaOp($this->core->loader),
            'get_search_list/0' => new SearchListOp($this->core->state),
            default             => null,
        };
    }

    private function bind(Bind $node, ?Scope $scope): Op
    {
        $source = $this->compile($node->source, $scope);
        if (1 === \count($node->patterns)) {
            $pattern = $node->patterns[0];
            if ($pattern instanceof VariablePattern) {
                $body = $this->compile($node->body, Scope::variable($scope, $pattern->name));
                if ($source instanceof SingleOp && $body instanceof SingleOp) {
                    return new SingleBindVarOp($source, $body);
                }

                return new BindVarOp($source, $body);
            }

            [$binder, $inner] = $this->pattern($pattern, $scope);

            return new BindOp($source, $binder, $this->compile($node->body, $inner));
        }

        $binders   = [];
        $events    = [];
        $variables = [];
        foreach ($node->patterns as $pattern) {
            [$binder, , $names] = $this->pattern($pattern, $scope);
            $binders[]          = $binder;
            $events[]           = $names;
            foreach ($names as $name) {
                if (!\in_array($name, $variables, true)) {
                    $variables[] = $name;
                }
            }
        }

        $inner = $scope;
        foreach ($variables as $variable) {
            $inner = Scope::variable($inner, $variable);
        }

        \assert([] !== $binders && [] !== $events);

        return new BindAltOp($source, $binders, $events, $variables, $this->compile($node->body, $inner));
    }

    private function reduce(Reduce $node, ?Scope $scope): Op
    {
        $source           = $this->compile($node->source, $scope);
        $init             = $this->compile($node->init, $scope);
        [$binder, $inner] = $this->pattern($node->pattern, $scope);

        return new ReduceOp($source, $binder, $init, $this->compile($node->update, $inner));
    }

    private function foreach(ForeachLoop $node, ?Scope $scope): Op
    {
        $source           = $this->compile($node->source, $scope);
        $init             = $this->compile($node->init, $scope);
        [$binder, $inner] = $this->pattern($node->pattern, $scope);

        return new ForeachOp(
            $source,
            $binder,
            $init,
            $this->compile($node->update, $inner),
            null === $node->extract ? null : $this->compile($node->extract, $inner),
        );
    }

    /**
     * Compile a pattern: its binder, the scope after all its variables, and the variable names in the order
     * the binder pushes them.
     *
     * @return array{Binder, ?Scope, list<string>}
     */
    private function pattern(Pattern $pattern, ?Scope $scope): array
    {
        if ($pattern instanceof VariablePattern) {
            return [new VarBinder(), Scope::variable($scope, $pattern->name), [$pattern->name]];
        }

        if ($pattern instanceof ArrayPattern) {
            $elements = [];
            $names    = [];
            foreach ($pattern->elements as $element) {
                [$binder, $scope, $elementNames] = $this->pattern($element, $scope);
                $elements[]                      = $binder;
                $names                           = [...$names, ...$elementNames];
            }

            \assert([] !== $elements);

            return [new ArrayBinder($elements), $scope, $names];
        }

        if (!$pattern instanceof ObjectPattern) {
            throw new LogicException('Unsupported pattern ' . $pattern::class);
        }

        $entries = [];
        $names   = [];
        foreach ($pattern->entries as $entry) {
            $keyOp = null;
            if (null !== $entry->key) {
                self::assertObjectKey($entry->key);
                $keyOp = $this->compile($entry->key, $scope);
            }

            if (null !== $entry->variable) {
                $scope   = Scope::variable($scope, $entry->variable);
                $names[] = $entry->variable;
            }

            $binder = null;
            if (null !== $entry->value) {
                [$binder, $scope, $valueNames] = $this->pattern($entry->value, $scope);
                $names                         = [...$names, ...$valueNames];
            }

            $entries[] = [$entry->variable, $keyOp, $binder];
        }

        \assert([] !== $entries);

        return [new ObjectBinder($entries), $scope, $names];
    }

    private function interpolation(StringInterpolation $node, ?Scope $scope): Op
    {
        $parts  = [];
        $single = true;
        foreach ($node->parts as $part) {
            if (\is_string($part)) {
                $parts[] = $part;

                continue;
            }

            $op      = $this->compile($part, $scope);
            $single  = $single && $op instanceof SingleOp;
            $parts[] = $op;
        }

        $format = $this->formatter($node->format);
        if ($single) {
            $singles = [];
            foreach ($parts as $part) {
                \assert(\is_string($part) || $part instanceof SingleOp);
                $singles[] = $part;
            }

            return new SingleStringInterpOp($singles, $format);
        }

        return new StringInterpOp($parts, $format);
    }

    /**
     * The conversion applied to a value by `@name` (null: plain tostring).
     *
     * @return Closure(mixed): string
     *
     * @throws JqCompileException
     */
    private function formatter(?string $name): Closure
    {
        if (null === $name || 'text' === $name) {
            return static fn (mixed $value): string => \is_string($value) ? $value : ErrorText::json($value);
        }

        if (!\in_array($name, self::FORMATS, true)) {
            throw new JqCompileException(\sprintf('%s is not a valid format at <top-level>, line 1:', $name));
        }

        $builtin = $this->core->builtins->lookup('format', 1);
        if (!$builtin instanceof ValueBuiltin) {
            throw new JqCompileException('format/1 is not defined at <top-level>, line 1:');
        }

        $state = $this->core->state;

        return static function (mixed $value) use ($builtin, $name, $state): string {
            $text = $builtin->call($state->context(), $value, [$name]);

            return \is_string($text) ? $text : ErrorText::json($text);
        };
    }
}
