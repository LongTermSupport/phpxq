<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * An engine object (a registry, compiler, parser, encoder, ...) built with the same arguments on every pass of a
 * loop is built once too often.
 *
 * Two shapes are reported inside a loop body or inside a callback given to a native iteration function:
 *
 *  - `new Engine(...)` whose arguments are constants or variables the loop never writes;
 *  - a call to a method of the same class that builds such an object on every call, without keeping it in a
 *    property, a static variable or a `??=` slot. The second shape is how one test rebuilt a builtin registry on
 *    each of 24,000 calls: the loop called a helper that called a helper that said `new Registry()`.
 *
 * A class counts as an engine by the ending of its name (see ENGINE_SUFFIXES). Construction that depends on the
 * iteration (`new Parser($line)`), happens once (`return new Engine()` inside the loop) or is kept is not reported.
 *
 * @implements Rule<InClassNode>
 */
final readonly class LoopInvariantConstructionRule implements Rule
{
    public const string IDENTIFIER = 'phpxq.loopInvariantConstruction';

    /** @var list<string> */
    private const array ENGINE_SUFFIXES = ['registry', 'catalog', 'compiler', 'factory', 'parser', 'lexer', 'scanner', 'encoder', 'decoder', 'emitter', 'evaluator', 'loader'];

    /** @var list<string> */
    private const array ITERATING_FUNCTIONS = ['array_map', 'array_filter', 'array_walk', 'array_reduce', 'usort', 'uasort', 'uksort', 'array_any', 'array_all', 'array_find', 'array_find_key', 'preg_replace_callback'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class    = $node->getOriginalNode();
        $short    = $class->name instanceof Identifier ? strtolower($class->name->toString()) : '';
        $methods  = ClassCallGraph::of($class)->methods();
        $builders = $this->builders($methods, $short);

        $errors   = [];
        $reported = [];
        foreach ($methods as $method) {
            $kept = $this->keptNodes($method);
            foreach ($this->loops($method) as $loop) {
                $written = $this->writtenVariables($loop);
                $exiting = $this->exitingNodes($loop);
                foreach ($this->bodyNodes($loop) as $inside) {
                    $id = spl_object_id($inside);
                    if (isset($reported[$id]) || isset($kept[$id]) || isset($exiting[$id])) {
                        continue;
                    }

                    $message = $this->violation($inside, $written, $builders, $short);
                    if (null === $message) {
                        continue;
                    }

                    $reported[$id] = true;
                    $errors[]      = RuleErrorBuilder::message($message)->identifier(self::IDENTIFIER)->line($inside->getStartLine())->build();
                }
            }
        }

        return $errors;
    }

    /**
     * The own methods that construct an engine on every call, with the name of the engine class.
     *
     * @param list<ClassMethod> $methods
     *
     * @return array<string, string>
     */
    private function builders(array $methods, string $short): array
    {
        $builders = [];
        do {
            $before = \count($builders);
            foreach ($methods as $method) {
                $name = strtolower($method->name->toString());
                if (isset($builders[$name]) || $this->keepsState($method)) {
                    continue;
                }

                $kept = $this->keptNodes($method);
                foreach (UnconditionalNodes::within($method->stmts ?? []) as $inside) {
                    if (isset($kept[spl_object_id($inside)])) {
                        continue;
                    }

                    $engine = $this->engineBuiltBy($inside, $builders, $short);
                    if (null !== $engine) {
                        $builders[$name] = $engine;

                        break;
                    }
                }
            }
        } while (\count($builders) !== $before);

        return $builders;
    }

    /**
     * @param array<string, string> $builders
     */
    private function engineBuiltBy(Node $node, array $builders, string $short): ?string
    {
        if ($node instanceof New_) {
            $engine = $this->engineName($node);

            return null !== $engine && $this->argumentsAreInvariant($node, [], false) ? $engine : null;
        }

        return $this->builderCalledBy($node, $builders, $short);
    }

    /**
     * @param array<string, string> $builders
     */
    private function builderCalledBy(Node $node, array $builders, string $short): ?string
    {
        if (!$node instanceof MethodCall && !$node instanceof StaticCall) {
            return null;
        }

        $targets = ClassCallGraph::targetsIn([$node], $short);
        if ([] === $targets || $node->isFirstClassCallable()) {
            return null;
        }

        return $builders[$targets[0]] ?? null;
    }

    /**
     * @param array<string, true>   $written
     * @param array<string, string> $builders
     */
    private function violation(Node $node, array $written, array $builders, string $short): ?string
    {
        if ($node instanceof New_) {
            $engine = $this->engineName($node);
            if (null !== $engine && $this->argumentsAreInvariant($node, $written, true)) {
                return \sprintf('new %s(...) is built with arguments that do not change between iterations, so every pass of this loop builds it again. Build it once before the loop, or keep it in a property.', $engine);
            }

            return null;
        }

        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            $engine = $this->builderCalledBy($node, $builders, $short);
            if (null !== $engine) {
                return \sprintf('%s() builds a new %s on every call and this call is inside a loop. Keep the instance in a property (`??=`), or build it once before the loop.', $node->name instanceof Identifier ? $node->name->toString() : 'method', $engine);
            }
        }

        return null;
    }

    private function engineName(New_ $new): ?string
    {
        if (!$new->class instanceof Name) {
            return null;
        }

        $name = $new->class->getLast();
        foreach (self::ENGINE_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($name), $suffix)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Whether every argument is made of constants, `$this` properties and, when allowed, variables the loop does
     * not write.
     *
     * @param array<string, true> $written
     */
    private function argumentsAreInvariant(New_ $new, array $written, bool $allowLocals): bool
    {
        foreach ($new->args as $argument) {
            if (!$argument instanceof Arg || !$this->isInvariant($argument->value, $written, $allowLocals)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, true> $written
     */
    private function isInvariant(Expr $expression, array $written, bool $allowLocals): bool
    {
        if ($expression instanceof InterpolatedString) {
            return false;
        }

        if ($expression instanceof Scalar || $expression instanceof ConstFetch || $expression instanceof ClassConstFetch) {
            return true;
        }

        if ($expression instanceof Variable) {
            return \is_string($expression->name) && ('this' === $expression->name || ($allowLocals && !isset($written[$expression->name])));
        }

        if ($expression instanceof PropertyFetch) {
            return $expression->var instanceof Variable && 'this' === $expression->var->name && $expression->name instanceof Identifier;
        }

        if ($expression instanceof Expr\Array_) {
            foreach ($expression->items as $item) {
                if (!$this->isInvariant($item->value, $written, $allowLocals)) {
                    return false;
                }
            }

            return true;
        }

        return $expression instanceof New_ && $this->argumentsAreInvariant($expression, $written, $allowLocals);
    }

    /**
     * Whether the method keeps what it builds: a `static` variable makes every construction in it a cache fill.
     */
    private function keepsState(ClassMethod $method): bool
    {
        return new NodeFinder()->findFirst($method->stmts ?? [], static fn (Node $found): bool => $found instanceof Static_) instanceof Node;
    }

    /**
     * Ids of the nodes on the right-hand side of an assignment into a property, a static property or a `??=`
     * slot: the object is being kept, not rebuilt.
     *
     * @return array<int, true>
     */
    private function keptNodes(ClassMethod $method): array
    {
        $kept   = [];
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($method->stmts ?? [], Assign::class) as $assignment) {
            if ($this->isKeptTarget($assignment->var)) {
                $kept += $this->idsWithin($assignment->expr);
            }
        }

        foreach ($finder->findInstanceOf($method->stmts ?? [], AssignOp::class) as $assignment) {
            if ($assignment instanceof Coalesce || $this->isKeptTarget($assignment->var)) {
                $kept += $this->idsWithin($assignment->expr);
            }
        }

        return $kept;
    }

    private function isKeptTarget(Expr $target): bool
    {
        while ($target instanceof ArrayDimFetch) {
            $target = $target->var;
        }

        return $target instanceof PropertyFetch || $target instanceof StaticPropertyFetch;
    }

    /**
     * @return array<int, true>
     */
    private function idsWithin(Node $root): array
    {
        $ids = [spl_object_id($root) => true];
        foreach (new NodeFinder()->find([$root], static fn (): bool => true) as $inside) {
            $ids[spl_object_id($inside)] = true;
        }

        return $ids;
    }

    /**
     * Statements that repeat their body, and the callbacks handed to native iteration functions.
     *
     * @return list<Node>
     */
    private function loops(ClassMethod $method): array
    {
        $loops = [];
        foreach (new NodeFinder()->find($method->stmts ?? [], static fn (Node $found): bool => $found instanceof For_ || $found instanceof Foreach_ || $found instanceof While_ || $found instanceof Do_ || $found instanceof FuncCall) as $found) {
            if (!$found instanceof FuncCall) {
                $loops[] = $found;

                continue;
            }

            if (!$found->name instanceof Name || !\in_array(strtolower($found->name->getLast()), self::ITERATING_FUNCTIONS, true)) {
                continue;
            }

            foreach ($found->args as $argument) {
                if ($argument instanceof Arg && ($argument->value instanceof Closure || $argument->value instanceof ArrowFunction)) {
                    $loops[] = $argument->value;
                }
            }
        }

        return $loops;
    }

    /**
     * The nodes evaluated on every pass: the body, plus the condition and step of a `for`/`while`.
     *
     * @return list<Node>
     */
    private function bodyNodes(Node $loop): array
    {
        $roots = match (true) {
            $loop instanceof For_          => [...$loop->cond, ...$loop->loop, ...$loop->stmts],
            $loop instanceof While_, $loop instanceof Do_ => [$loop->cond, ...$loop->stmts],
            $loop instanceof Foreach_, $loop instanceof Closure => $loop->stmts,
            $loop instanceof ArrowFunction => [$loop->expr],
            default                        => [],
        };

        return array_values(new NodeFinder()->find($roots, static fn (): bool => true));
    }

    /**
     * Ids of the nodes under a `return` in the loop: that pass leaves the loop, so it builds at most once.
     *
     * @return array<int, true>
     */
    private function exitingNodes(Node $loop): array
    {
        $ids = [];
        foreach (new NodeFinder()->findInstanceOf($this->bodyNodes($loop), Return_::class) as $return) {
            $ids += $this->idsWithin($return);
        }

        return $ids;
    }

    /**
     * The variables a pass of the loop assigns, increments, unsets or binds as its iteration variables.
     *
     * @return array<string, true>
     */
    private function writtenVariables(Node $loop): array
    {
        $nodes = $this->bodyNodes($loop);
        if ($loop instanceof For_) {
            $nodes = [...$nodes, ...$loop->init];
        }

        $targets = [];
        foreach ([$loop, ...$nodes] as $writer) {
            foreach ($this->writtenBy($writer) as $target) {
                $targets[] = $target;
            }
        }

        if ($loop instanceof Closure || $loop instanceof ArrowFunction) {
            foreach ($loop->params as $parameter) {
                $targets[] = $parameter->var;
            }
        }

        $names = [];
        foreach (new NodeFinder()->findInstanceOf($targets, Variable::class) as $variable) {
            if (\is_string($variable->name)) {
                $names[$variable->name] = true;
            }
        }

        return $names;
    }

    /**
     * @return list<Expr>
     */
    private function writtenBy(Node $writer): array
    {
        return match (true) {
            $writer instanceof Foreach_ => $writer->keyVar instanceof Expr ? [$writer->valueVar, $writer->keyVar] : [$writer->valueVar],
            $writer instanceof Unset_   => array_values($writer->vars),
            $writer instanceof Closure  => array_map(static fn (ClosureUse $use): Expr => $use->var, $writer->uses),
            $writer instanceof Assign, $writer instanceof AssignOp, $writer instanceof AssignRef, $writer instanceof PreInc, $writer instanceof PostInc, $writer instanceof PreDec, $writer instanceof PostDec => [$writer->var],
            default                     => [],
        };
    }
}
