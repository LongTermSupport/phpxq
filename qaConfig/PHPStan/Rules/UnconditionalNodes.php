<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * The nodes a run of some statements always evaluates: straight-line code, without anything behind a branch, a
 * loop body, a `catch`, the right side of a short-circuit operator, or a closure that may never be called.
 */
final class UnconditionalNodes extends NodeVisitorAbstract
{
    /** @var list<Node> */
    private array $found = [];

    private function __construct()
    {
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<Node>
     */
    public static function within(array $nodes): array
    {
        $collector = new self();
        $collector->collect($nodes);

        return $collector->found;
    }

    public function enterNode(Node $node): ?int
    {
        $this->found[] = $node;

        $always = $this->alwaysEvaluatedChildren($node);
        if (null === $always) {
            return null;
        }

        $this->collect($always);

        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    /**
     * @param array<Node> $nodes
     */
    private function collect(array $nodes): void
    {
        new NodeTraverser($this)->traverse($nodes);
    }

    /**
     * Null when every child is evaluated, otherwise the only children that are.
     *
     * @return list<Node>|null
     */
    private function alwaysEvaluatedChildren(Node $node): ?array
    {
        return match (true) {
            $node instanceof If_, $node instanceof ElseIf_, $node instanceof Switch_, $node instanceof Ternary, $node instanceof Match_                                                                => [$node->cond],
            $node instanceof Foreach_                                                                                                                                                                  => [$node->expr],
            $node instanceof For_                                                                                                                                                                      => array_values($node->init),
            $node instanceof TryCatch                                                                                                                                                                  => array_values($node->stmts),
            $node instanceof BooleanAnd, $node instanceof BooleanOr, $node instanceof LogicalAnd, $node instanceof LogicalOr, $node instanceof Coalesce                                                => [$node->left],
            $node instanceof While_, $node instanceof Do_, $node instanceof Closure, $node instanceof ArrowFunction, $node instanceof Class_, $node instanceof Function_, $node instanceof ClassMethod => [],
            default                                                                                                                                                                                    => null,
        };
    }
}
