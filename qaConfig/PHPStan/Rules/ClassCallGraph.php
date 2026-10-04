<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * The calls a class makes to its own methods, and which of its methods can reach themselves again.
 *
 * A call is `$this->name()`, `self::name()`, `static::name()` or `OwnClass::name()`; a first-class callable
 * (`$this->name(...)`) counts as a call because whoever receives it can invoke it. Method names are compared
 * in lower case, as PHP does.
 */
final readonly class ClassCallGraph
{
    /**
     * @param array<string, ClassMethod>         $methods methods by lower-case name
     * @param array<string, array<string, true>> $reach   for each method, every method it can reach through any number of calls
     */
    private function __construct(private array $methods, private array $reach)
    {
    }

    public static function of(ClassLike $class): self
    {
        $methods = [];
        foreach ($class->getMethods() as $method) {
            $methods[strtolower($method->name->toString())] = $method;
        }

        $shortName = strtolower($class->name instanceof Identifier ? $class->name->toString() : '');
        $reach     = [];
        foreach ($methods as $name => $method) {
            $reach[$name] = [];
            foreach (self::targetsIn($method->stmts ?? [], $shortName) as $target) {
                if (isset($methods[$target])) {
                    $reach[$name][$target] = true;
                }
            }
        }

        do {
            $changed = false;
            foreach ($reach as $from => $reached) {
                foreach (array_keys($reached) as $via) {
                    foreach (array_keys($reach[$via]) as $onward) {
                        if (!isset($reach[$from][$onward])) {
                            $reach[$from][$onward] = true;
                            $changed               = true;
                        }
                    }
                }
            }
        } while ($changed);

        return new self($methods, $reach);
    }

    /**
     * The lower-case names of own methods called anywhere inside the given nodes.
     *
     * @param array<Node> $nodes
     *
     * @return list<string>
     */
    public static function targetsIn(array $nodes, string $shortName): array
    {
        $targets = [];
        foreach (new NodeFinder()->find($nodes, static fn (Node $node): bool => $node instanceof MethodCall || $node instanceof StaticCall) as $call) {
            if ($call instanceof MethodCall && $call->var instanceof Variable && 'this' === $call->var->name && $call->name instanceof Identifier) {
                $targets[] = strtolower($call->name->toString());
            }

            if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier && self::namesOwnClass($call->class, $shortName)) {
                $targets[] = strtolower($call->name->toString());
            }
        }

        return $targets;
    }

    /**
     * @return list<ClassMethod>
     */
    public function methods(): array
    {
        return array_values($this->methods);
    }

    public function isRecursive(string $method): bool
    {
        return isset($this->reach[strtolower($method)][strtolower($method)]);
    }

    /**
     * Whether each of the two methods can reach the other, so a call to $other from inside $method can come back.
     */
    public function inSameCycle(string $method, string $other): bool
    {
        $method = strtolower($method);
        $other  = strtolower($other);

        return isset($this->reach[$method][$other], $this->reach[$other][$method]);
    }

    private static function namesOwnClass(Name $name, string $shortName): bool
    {
        $written = strtolower($name->getLast());

        return \in_array($written, ['self', 'static'], true) || ('' !== $shortName && $written === $shortName);
    }
}
