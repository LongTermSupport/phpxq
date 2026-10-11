<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A method that recurses through a callback handed to a native function keeps one native frame per level.
 *
 * PHP calls between userland functions use the VM's own heap-allocated frames, so plain recursion is bounded by
 * memory. A callback invoked by `array_map`, `array_all`, `usort` and the like is entered from C instead, and the
 * C stack is exhausted after a few thousand levels with "Maximum call stack size reached", on data (a deeply
 * nested document, a cyclic alias graph) that the same recursion written as a loop survives.
 *
 * Only recursion inside one class is seen: a method that reaches itself, directly or through its sibling
 * methods, with the recursive call inside the callback.
 *
 * @implements Rule<InClassNode>
 */
final readonly class RecursionThroughNativeCallbackRule implements Rule
{
    public const string IDENTIFIER = 'phpxq.recursionThroughNativeCallback';

    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

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

        $errors = [];
        foreach ($graph->methods() as $method) {
            if (!$graph->isRecursive($method->name->toString())) {
                continue;
            }

            foreach ($this->nativeCalls($method, $scope) as $native) {
                foreach ($native->args as $argument) {
                    if (!$argument instanceof Arg) {
                        continue;
                    }

                    $callback = $argument->value;
                    if ($this->recurses($callback, $graph, $method, $short)) {
                        $errors[] = RuleErrorBuilder::message(\sprintf(
                            '%s::%s() recurses through a callback given to native %s(): each level keeps a native stack frame, which runs out after a few thousand levels. Use an indexed loop instead.',
                            $class->name instanceof Identifier ? $class->name->toString() : 'class@anonymous',
                            $method->name->toString(),
                            $native->name instanceof Name ? $native->name->toString() : '',
                        ))->identifier(self::IDENTIFIER)->line($callback->getStartLine())->build();
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<FuncCall>
     */
    private function nativeCalls(ClassMethod $method, Scope $scope): array
    {
        $calls = [];
        foreach (new NodeFinder()->findInstanceOf($method->stmts ?? [], FuncCall::class) as $call) {
            if ($call->name instanceof Name && $this->reflectionProvider->hasFunction($call->name, $scope) && $this->reflectionProvider->getFunction($call->name, $scope)->isBuiltin()) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    private function recurses(Node $callback, ClassCallGraph $graph, ClassMethod $method, string $shortName): bool
    {
        if ($callback instanceof Closure) {
            $inside = $callback->stmts;
        } elseif ($callback instanceof ArrowFunction) {
            $inside = [$callback->expr];
        } elseif ($callback instanceof CallLike && $callback->isFirstClassCallable()) {
            $inside = [$callback];
        } else {
            return false;
        }

        return array_any(ClassCallGraph::targetsIn(array_values($inside), $shortName), static fn (string $target): bool => $graph->inSameCycle($method->name->toString(), $target));
    }
}
