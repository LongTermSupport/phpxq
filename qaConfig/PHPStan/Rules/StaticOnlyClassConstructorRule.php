<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A class that holds only static members and declares no constructor can be instantiated for no purpose.
 *
 * `new Helper()` compiles and runs, and the object has no state and no behaviour, so a stray instantiation is a
 * mistake nothing reports. A private constructor turns that mistake into an error at the call site.
 *
 * Reported: a concrete named class with at least one static method, static property or constant, no instance
 * method or property, and no constructor. Not judged, because the class may be instantiable on purpose or the
 * constructor may live elsewhere: abstract and anonymous classes, classes with a constructor of any visibility,
 * classes with an instance member, classes that use a trait or extend a parent, and classes with no members.
 *
 * @implements Rule<InClassNode>
 */
final readonly class StaticOnlyClassConstructorRule implements Rule
{
    public const string IDENTIFIER = 'phpxq.staticOnlyClassConstructor';

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
        if (!$class instanceof Class_ || !$class->name instanceof Node\Identifier || $node->getClassReflection()->isAnonymous() || $class->isAbstract() || $class->extends instanceof Node\Name) {
            return [];
        }

        $hasStaticMember = false;
        foreach ($class->stmts as $statement) {
            if ($statement instanceof TraitUse) {
                return [];
            }

            if ($statement instanceof ClassMethod) {
                if ('__construct' === $statement->name->toLowerString()) {
                    return [];
                }

                if (!$statement->isStatic()) {
                    return [];
                }

                $hasStaticMember = true;
            }

            if ($statement instanceof Property) {
                if (!$statement->isStatic()) {
                    return [];
                }

                $hasStaticMember = true;
            }

            if ($statement instanceof ClassConst) {
                $hasStaticMember = true;
            }
        }

        if (!$hasStaticMember) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                '%s has only static members but declares no constructor, so `new %s()` builds a useless instance. Add `private function __construct()` as a guard.',
                $class->name->toString(),
                $class->name->toString(),
            ))->identifier(self::IDENTIFIER)->line($class->name->getStartLine())->build(),
        ];
    }
}
