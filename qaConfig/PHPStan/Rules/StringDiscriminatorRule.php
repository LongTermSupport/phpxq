<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A kind, mode, format, tool or flag name held in a variable or property and told apart by comparing it with
 * word-like string literals: `'head' === $kind`, `match ($format) { 'json' => ..., 'yaml' => ... }`,
 * `in_array($mode, ['a', 'b'], true)`, `switch ($name) { case 'x': ... }`.
 *
 * One finding per method and subject expression, when that expression meets two or more different literals
 * through any mix of those forms. The set of values is closed and has no single definition: a backed enum
 * (resolved once with `tryFrom` at the boundary) or class constants give it one, and a misspelt literal
 * stops being a silent never-matching branch.
 *
 * A literal counts when it is word-like (letters, digits, `_`, `-`, starting with a letter or `_`). Punctuation
 * and the empty string are not names. A subject is a variable, a property of one or a static property.
 *
 * Code whose job is to read a text syntax (a lexer or parser comparing token text with the keywords of the
 * language it reads) is named by namespace prefix in the rule's `syntaxNamespaces` argument; the reasons are
 * recorded in docs/phpstan-rules/string-discriminator.md.
 *
 * @implements Rule<InClassNode>
 */
final readonly class StringDiscriminatorRule implements Rule
{
    public const string IDENTIFIER = 'phpxq.stringDiscriminator';

    private const string WORD_LIKE = '/^[A-Za-z_][A-Za-z0-9_-]*$/D';

    private const int MAX_SHOWN = 6;

    /** @var list<string> */
    private array $syntaxNamespaces;

    /**
     * @param list<string> $syntaxNamespaces namespace prefixes whose classes read a text syntax
     */
    public function __construct(array $syntaxNamespaces = [])
    {
        $this->syntaxNamespaces = array_map(static fn (string $prefix): string => rtrim($prefix, '\\') . '\\', $syntaxNamespaces);
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
        $name  = $node->getClassReflection()->isAnonymous() ? (string)$scope->getNamespace() : $node->getClassReflection()->getName();
        foreach ($this->syntaxNamespaces as $prefix) {
            if (str_starts_with($name . '\\', $prefix)) {
                return [];
            }
        }

        $errors = [];
        foreach ($class->getMethods() as $method) {
            foreach ($this->discriminators($method) as $subject => $found) {
                $literals = array_keys($found['literals']);
                if (\count($literals) < 2) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message($this->message($subject, $method, ...$literals))
                    ->identifier(self::IDENTIFIER)
                    ->line($found['line'])
                    ->build()
                ;
            }
        }

        return $errors;
    }

    private function message(string $subject, ClassMethod $method, string ...$literals): string
    {
        $shown = array_map(static fn (string $literal): string => "'" . $literal . "'", \array_slice($literals, 0, self::MAX_SHOWN));
        $more  = \count($literals) > self::MAX_SHOWN ? ', ...' : '';

        return \sprintf(
            '%s is told apart in %s() by comparing it with the string literals %s%s: a closed set of names written as strings. Declare a backed enum and resolve it once with tryFrom() at the boundary, or give each value a class constant.',
            $subject,
            $method->name->toString(),
            implode(', ', $shown),
            $more,
        );
    }

    /**
     * Subject expression => the distinct literals it is compared with, and the line of the first comparison.
     *
     * @return array<string, array{literals: array<string, true>, line: int}>
     */
    private function discriminators(ClassMethod $method): array
    {
        $found = [];
        foreach ($this->nodesOf($method) as $inside) {
            foreach ($this->comparisons($inside) as [$subject, $literal, $line]) {
                $found[$subject]['literals'][$literal] = true;
                $found[$subject]['line']             ??= $line;
            }
        }

        return $found;
    }

    /**
     * Every node of the method body except those of an anonymous class, which is analysed as a class of its own.
     *
     * @return list<Node>
     */
    private function nodesOf(ClassMethod $method): array
    {
        $collector = new class extends NodeVisitorAbstract {
            /** @var list<Node> */
            public array $nodes = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Class_) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                $this->nodes[] = $node;

                return null;
            }
        };
        new NodeTraverser($collector)->traverse($method->stmts ?? []);

        return $collector->nodes;
    }

    /**
     * @return list<array{string, string, int}> subject, literal, line
     */
    private function comparisons(Node $node): array
    {
        if ($node instanceof Identical || $node instanceof NotIdentical || $node instanceof Equal || $node instanceof NotEqual) {
            return $this->pair($node->left, $node->right, $node->getStartLine());
        }

        if ($node instanceof Match_) {
            $subject = $this->subjectKey($node->cond);
            $pairs   = [];
            foreach ($node->arms as $arm) {
                foreach ($arm->conds ?? [] as $condition) {
                    $pairs = [...$pairs, ...$this->withSubject($subject, $condition, $condition->getStartLine())];
                }
            }

            return $pairs;
        }

        if ($node instanceof Switch_) {
            $subject = $this->subjectKey($node->cond);
            $pairs   = [];
            foreach ($node->cases as $case) {
                if ($case->cond instanceof Expr) {
                    $pairs = [...$pairs, ...$this->withSubject($subject, $case->cond, $case->cond->getStartLine())];
                }
            }

            return $pairs;
        }

        if ($node instanceof FuncCall && $node->name instanceof Name && 'in_array' === strtolower($node->name->getLast())) {
            return $this->inArray($node);
        }

        return [];
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function inArray(FuncCall $call): array
    {
        $needle   = $this->argument($call, 0, 'needle');
        $haystack = $this->argument($call, 1, 'haystack');
        if (!$needle instanceof Expr || !$haystack instanceof Array_) {
            return [];
        }

        $subject = $this->subjectKey($needle);
        $pairs   = [];
        foreach ($haystack->items as $item) {
            if (!$item->unpack) {
                $pairs = [...$pairs, ...$this->withSubject($subject, $item->value, $item->getStartLine())];
            }
        }

        return $pairs;
    }

    /**
     * The argument at a position, or given by name; null when absent, spread or a first-class callable.
     */
    private function argument(FuncCall $call, int $position, string $name): ?Expr
    {
        foreach ($call->args as $index => $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                continue;
            }

            if ($argument->name instanceof Identifier ? $name === $argument->name->toString() : $position === $index) {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function pair(Expr $left, Expr $right, int $line): array
    {
        return [
            ...$this->withSubject($this->subjectKey($left), $right, $line),
            ...$this->withSubject($this->subjectKey($right), $left, $line),
        ];
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function withSubject(?string $subject, Expr $literal, int $line): array
    {
        if (null === $subject || !$literal instanceof String_ || 1 !== preg_match(self::WORD_LIKE, $literal->value)) {
            return [];
        }

        return [[$subject, $literal->value, $line]];
    }

    /**
     * A variable, a property of one (nullsafe too) or a static property, spelled out; null for anything else.
     */
    private function subjectKey(Expr $expression): ?string
    {
        $parts = [];
        $inner = $expression;
        while ($inner instanceof PropertyFetch || $inner instanceof NullsafePropertyFetch) {
            if (!$inner->name instanceof Identifier) {
                return null;
            }

            array_unshift($parts, '->' . $inner->name->toString());
            $inner = $inner->var;
        }

        if ($inner instanceof StaticPropertyFetch) {
            return $inner->class instanceof Name && $inner->name instanceof Node\VarLikeIdentifier ? $inner->class->toString() . '::$' . $inner->name->toString() . implode('', $parts) : null;
        }

        if ($inner instanceof Variable && \is_string($inner->name)) {
            return '$' . $inner->name . implode('', $parts);
        }

        return null;
    }
}
