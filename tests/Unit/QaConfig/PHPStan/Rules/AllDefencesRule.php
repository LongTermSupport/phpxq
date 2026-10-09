<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig\PHPStan\Rules;

use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use QaConfig\PHPStan\Rules\LoopInvariantConstructionRule;
use QaConfig\PHPStan\Rules\RecursionThroughNativeCallbackRule;
use QaConfig\PHPStan\Rules\StaticOnlyClassConstructorRule;
use QaConfig\PHPStan\Rules\StringDiscriminatorRule;
use QaConfig\PHPStan\Rules\UnguardedAliasRecursionRule;

/**
 * Runs every project defence that works on a class in one pass, so a test pays for parsing and scope resolution once.
 *
 * @implements Rule<InClassNode>
 */
final readonly class AllDefencesRule implements Rule
{
    private UnguardedAliasRecursionRule $aliasRecursion;

    private RecursionThroughNativeCallbackRule $nativeCallback;

    private LoopInvariantConstructionRule $loopConstruction;

    private StringDiscriminatorRule $stringDiscriminator;

    private StaticOnlyClassConstructorRule $staticOnlyConstructor;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->aliasRecursion        = new UnguardedAliasRecursionRule($reflectionProvider);
        $this->nativeCallback        = new RecursionThroughNativeCallbackRule($reflectionProvider);
        $this->loopConstruction      = new LoopInvariantConstructionRule();
        $this->stringDiscriminator   = new StringDiscriminatorRule();
        $this->staticOnlyConstructor = new StaticOnlyClassConstructorRule();
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        return [
            ...$this->aliasRecursion->processNode($node, $scope),
            ...$this->nativeCallback->processNode($node, $scope),
            ...$this->loopConstruction->processNode($node, $scope),
            ...$this->stringDiscriminator->processNode($node, $scope),
            ...$this->staticOnlyConstructor->processNode($node, $scope),
        ];
    }
}
