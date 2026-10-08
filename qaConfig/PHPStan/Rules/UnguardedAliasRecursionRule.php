<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Recursion into a YAML node reached through an alias, with nothing to stop a cycle, never ends on a cyclic alias.
 *
 * An alias node points at the node carrying its anchor, and the anchor can sit above the alias (`&a [*a]`), so
 * following aliases while descending is a walk over a graph that may contain a cycle. A tree walk over `content`
 * alone terminates; one that resolves aliases on the way down and recurses into what it found does not, and runs
 * until memory or time is exhausted (a merge key that merges its own mapping crashed the process this way).
 *
 * An alias is resolved by a read of `aliasTarget`, by a call to a resolver of another class (`RESOLVERS`, lower-case
 * method names by lower-case short class name: `NodeOps::deref()`, `NodeTools::unwrap()`, the merge-source
 * resolver), or by a call to an own method whose return value comes from one of those, found transitively.
 *
 * A recursive call is accepted, because it still terminates, when
 *
 *  - one of its arguments is taken from a parameter that was never resolved through an alias (merging into a
 *    caller-owned tree descends that tree as well, and a tree is finite), or
 *  - the method, or a method in the same recursion cycle, takes an `int` parameter named `depth`, `nesting`,
 *    `remaining` or `budget` and compares it: the check is what stops the cycle, so a parameter that is only
 *    passed along does not count.
 *
 * Recursion inside one class is seen, directly or through sibling methods.
 *
 * @implements Rule<InClassNode>
 */
final readonly class UnguardedAliasRecursionRule implements Rule
{
    public const string IDENTIFIER = 'phpxq.unguardedAliasRecursion';

    /** @var array<string, list<string>> */
    private const array RESOLVERS = [
        'nodeops'   => ['deref', 'unwrap'],
        'nodetools' => ['deref', 'unwrap'],
        'traversal' => ['mergetargets'],
    ];

    private const string ALIAS_PROPERTY = 'aliastarget';

    /** @var list<string> */
    private const array GUARD_NAMES = ['depth', 'nesting', 'remaining', 'budget'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getOriginalNode();
        $graph = ClassCallGraph::of($class);
        $short = $class->name instanceof Identifier ? strtolower($class->name->toString()) : '';
        $own   = $this->ownResolvers($graph, $short);

        $errors = [];
        foreach ($graph->methods() as $method) {
            $name = $method->name->toString();
            if (!$graph->isRecursive($name) || $this->cycleIsBounded($name, $graph)) {
                continue;
            }

            if ($this->recursesIntoResolvedNode($method, $graph, $short, $own)) {
                $errors[] = RuleErrorBuilder::message(\sprintf(
                    '%s::%s() recurses into a node reached through an alias (NodeOps::deref, NodeTools::unwrap, Traversal::mergeTargets, aliasTarget, or an own method returning what one of them found) with no depth bound: a cyclic alias such as `&a [*a]` never ends. Take an int $depth, compare it with a MAX_DEPTH constant and fail past it.',
                    $class->name instanceof Identifier ? $class->name->toString() : 'class@anonymous',
                    $name,
                ))->identifier(self::IDENTIFIER)->line($method->name->getStartLine())->build();
            }
        }

        return $errors;
    }

    /**
     * Whether the method, or any method that can come back to it, compares a depth parameter.
     */
    private function cycleIsBounded(string $name, ClassCallGraph $graph): bool
    {
        return array_any($graph->methods(), fn (ClassMethod $candidate): bool => $graph->inSameCycle($name, $candidate->name->toString()) && $this->comparesDepth($candidate));
    }

    private function comparesDepth(ClassMethod $method): bool
    {
        $guards = [];
        foreach ($method->params as $parameter) {
            if ($parameter->var instanceof Variable && \is_string($parameter->var->name) && $parameter->type instanceof Identifier && 'int' === $parameter->type->toLowerString() && \in_array(strtolower($parameter->var->name), self::GUARD_NAMES, true)) {
                $guards[] = $parameter->var->name;
            }
        }

        if ([] === $guards) {
            return false;
        }

        foreach (new NodeFinder()->find($method->stmts ?? [], static fn (Node $found): bool => $found instanceof Greater || $found instanceof GreaterOrEqual || $found instanceof Smaller || $found instanceof SmallerOrEqual || $found instanceof Identical || $found instanceof Equal) as $comparison) {
            if (!$comparison instanceof Expr\BinaryOp) {
                continue;
            }

            foreach ([[$comparison->left, $comparison->right], [$comparison->right, $comparison->left]] as [$operand, $limit]) {
                if ($operand instanceof Variable && \in_array($operand->name, $guards, true) && ($limit instanceof Expr\ClassConstFetch || $limit instanceof Expr\ConstFetch)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The own methods whose return value comes from an alias, found transitively: a method that returns what another
     * such method returned is one as well.
     *
     * @return array<string, true> lower-case method names
     */
    private function ownResolvers(ClassCallGraph $graph, string $short): array
    {
        $resolvers = [];
        do {
            $before = \count($resolvers);
            foreach ($graph->methods() as $method) {
                $name = strtolower($method->name->toString());
                if (isset($resolvers[$name])) {
                    continue;
                }

                $body    = $method->stmts ?? [];
                $tainted = $this->taintedVariables($body, $short, $resolvers);
                foreach (new NodeFinder()->findInstanceOf($body, Return_::class) as $return) {
                    if ($return->expr instanceof Expr && $this->derivesFromAlias($return->expr, $tainted, $short, $resolvers)) {
                        $resolvers[$name] = true;

                        break;
                    }
                }
            }
        } while (\count($resolvers) !== $before);

        return $resolvers;
    }

    /**
     * @param array<string, true> $own lower-case names of the own methods that return a resolved node
     */
    private function recursesIntoResolvedNode(ClassMethod $method, ClassCallGraph $graph, string $short, array $own): bool
    {
        $body    = $method->stmts ?? [];
        $tainted = $this->taintedVariables($body, $short, $own);
        $tree    = $this->treeVariables($method, $tainted);

        foreach (new NodeFinder()->find($body, static fn (Node $found): bool => $found instanceof MethodCall || $found instanceof StaticCall) as $call) {
            if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
                continue;
            }

            if ($call->isFirstClassCallable()) {
                continue;
            }

            $targets = ClassCallGraph::targetsIn([$call], $short);
            if ([] === $targets || !$graph->inSameCycle($method->name->toString(), $targets[0])) {
                continue;
            }

            $followsAlias = false;
            $descendsTree = false;
            foreach ($call->getArgs() as $argument) {
                $followsAlias = $followsAlias || $this->derivesFromAlias($argument->value, $tainted, $short, $own);
                $descendsTree = $descendsTree || $this->descendsTree($argument->value, $tree, $tainted);
            }

            if ($followsAlias && !$descendsTree) {
                return true;
            }
        }

        return false;
    }

    /**
     * The variables that hold a node reached through an alias, or something taken out of one.
     *
     * @param array<array-key, Node> $body
     * @param array<string, true>    $own  lower-case names of the own methods that return a resolved node
     *
     * @return array<string, true>
     */
    private function taintedVariables(array $body, string $short, array $own): array
    {
        $tainted = [];
        do {
            $before = \count($tainted);
            foreach (new NodeFinder()->find($body, static fn (Node $found): bool => $found instanceof Assign || $found instanceof AssignOp || $found instanceof Foreach_) as $statement) {
                if ($statement instanceof Foreach_ && $this->derivesFromAlias($statement->expr, $tainted, $short, $own)) {
                    $tainted += $this->boundNames($statement->valueVar);
                    if ($statement->keyVar instanceof Expr) {
                        $tainted += $this->boundNames($statement->keyVar);
                    }
                }

                if (($statement instanceof Assign || $statement instanceof AssignOp) && !$statement->var instanceof PropertyFetch && $this->derivesFromAlias($statement->expr, $tainted, $short, $own)) {
                    $tainted += $this->boundNames($statement->var);
                }
            }
        } while (\count($tainted) !== $before);

        return $tainted;
    }

    /**
     * The parameters that were never resolved through an alias, plus the variables bound from them.
     *
     * @param array<string, true> $tainted
     *
     * @return array<string, true>
     */
    private function treeVariables(ClassMethod $method, array $tainted): array
    {
        $tree = [];
        foreach ($method->params as $parameter) {
            if ($parameter->var instanceof Variable && \is_string($parameter->var->name) && !isset($tainted[$parameter->var->name])) {
                $tree[$parameter->var->name] = true;
            }
        }

        do {
            $before = \count($tree);
            foreach (new NodeFinder()->find($method->stmts ?? [], static fn (Node $found): bool => $found instanceof Assign || $found instanceof Foreach_) as $statement) {
                if ($statement instanceof Foreach_ && $this->descendsTree($statement->expr, $tree, $tainted)) {
                    $tree += $this->boundNames($statement->valueVar);
                }

                if ($statement instanceof Assign && $this->descendsTree($statement->expr, $tree, $tainted)) {
                    $tree += $this->boundNames($statement->var);
                }
            }
        } while (\count($tree) !== $before);

        return array_diff_key($tree, $tainted);
    }

    /**
     * Whether the expression is taken out of a tree variable (`$tree->content[$i]`, a foreach variable over it)
     * without going through an alias.
     *
     * @param array<string, true> $tree
     * @param array<string, true> $tainted
     */
    private function descendsTree(Expr $expression, array $tree, array $tainted): bool
    {
        if ($expression instanceof Variable) {
            return \is_string($expression->name) && isset($tree[$expression->name]) && !isset($tainted[$expression->name]);
        }

        if (!$expression instanceof PropertyFetch && !$expression instanceof NullsafePropertyFetch && !$expression instanceof ArrayDimFetch) {
            return false;
        }

        for ($link = $expression; $link instanceof PropertyFetch || $link instanceof NullsafePropertyFetch || $link instanceof ArrayDimFetch; $link = $link->var) {
            if (!$link instanceof ArrayDimFetch && $link->name instanceof Identifier && self::ALIAS_PROPERTY === strtolower($link->name->toString())) {
                return false;
            }
        }

        return $this->rootedIn($expression, $tree);
    }

    /**
     * @param array<string, true> $tree
     */
    private function rootedIn(Expr $expression, array $tree): bool
    {
        while ($expression instanceof PropertyFetch || $expression instanceof NullsafePropertyFetch || $expression instanceof ArrayDimFetch) {
            $expression = $expression->var;
        }

        return $expression instanceof Variable && \is_string($expression->name) && isset($tree[$expression->name]);
    }

    /**
     * @param array<string, true> $tainted
     * @param array<string, true> $own     lower-case names of the own methods that return a resolved node
     */
    private function derivesFromAlias(Node $expression, array $tainted, string $short, array $own): bool
    {
        $found = new NodeFinder()->findFirst($expression, static function (Node $inside) use ($tainted, $short, $own): bool {
            if ($inside instanceof Variable) {
                return \is_string($inside->name) && isset($tainted[$inside->name]);
            }

            if ($inside instanceof PropertyFetch || $inside instanceof NullsafePropertyFetch) {
                return $inside->name instanceof Identifier && self::ALIAS_PROPERTY === strtolower($inside->name->toString());
            }

            if (!$inside instanceof MethodCall && !$inside instanceof StaticCall) {
                return false;
            }

            if (array_any(ClassCallGraph::targetsIn([$inside], $short), static fn (string $target): bool => isset($own[$target]))) {
                return true;
            }

            return $inside instanceof StaticCall
                && $inside->class instanceof Name
                && $inside->name instanceof Identifier
                && \in_array(strtolower($inside->name->toString()), self::RESOLVERS[strtolower($inside->class->getLast())] ?? [], true);
        });

        return $found instanceof Node;
    }

    /**
     * @return array<string, true>
     */
    private function boundNames(Expr $target): array
    {
        if ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch || $target instanceof NullsafePropertyFetch) {
            return $this->boundNames($target->var);
        }

        if ($target instanceof Variable) {
            return \is_string($target->name) ? [$target->name => true] : [];
        }

        $names = [];
        foreach (new NodeFinder()->findInstanceOf($target, Variable::class) as $variable) {
            if (\is_string($variable->name)) {
                $names[$variable->name] = true;
            }
        }

        return $names;
    }
}
