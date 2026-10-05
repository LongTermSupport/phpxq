<?php

declare(strict_types=1);

namespace QaConfig\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Runs another rule on production code only: a file under the excluded directory is never handed to
 * it. The wrapped rule keeps its own node type, messages and identifier, so a finding reads exactly as
 * if the rule were registered directly.
 *
 * Used for rules whose hazard is a production one. Test data repeats literals on purpose, one
 * assertion per value, and a shared constant there would only hide which case an assertion checks.
 *
 * @template TNode of Node
 *
 * @implements Rule<TNode>
 */
final readonly class ProductionOnlyRule implements Rule
{
    private string $excludedDirectory;

    /**
     * @param Rule<TNode> $inner
     */
    public function __construct(
        private Rule $inner,
        string $excludedDirectory,
    ) {
        $this->excludedDirectory = rtrim($excludedDirectory, '/') . '/';
    }

    public function getNodeType(): string
    {
        return $this->inner->getNodeType();
    }

    /**
     * @param TNode $node
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (str_starts_with($scope->getFile(), $this->excludedDirectory)) {
            return [];
        }

        return $this->inner->processNode($node, $scope);
    }
}
