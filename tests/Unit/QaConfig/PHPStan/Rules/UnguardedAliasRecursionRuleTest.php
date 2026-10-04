<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use QaConfig\PHPStan\Rules\UnguardedAliasRecursionRule;

/**
 * @internal
 *
 * @extends RuleTestCase<UnguardedAliasRecursionRule>
 */
#[CoversNothing]
final class UnguardedAliasRecursionRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/Defence/AliasRecursion';

    public function testItFlagsEveryWayOfFollowingAnAliasWithoutABound(): void
    {
        $this->analyse([self::FIXTURES . '/FollowsAliasesUnbounded.php'], [
            [$this->message('count'), 16],
            [$this->message('names'), 27],
            [$this->message('viaTarget'), 38],
            [$this->message('mutualA'), 49],
            [$this->message('depthIsNeverChecked'), 61],
        ]);
    }

    public function testItAcceptsACheckedDepthAndTreeWalksThatNeverFollowAnAlias(): void
    {
        $this->analyse([self::FIXTURES . '/BoundedOrNotFollowing.php'], []);
    }

    protected function getRule(): Rule
    {
        return new UnguardedAliasRecursionRule();
    }

    private function message(string $method): string
    {
        return \sprintf(
            '%s::%s() recurses into a node reached through an alias (NodeOps::deref, NodeTools::unwrap or aliasTarget) with no depth bound: a cyclic alias such as `&a [*a]` never ends. Take an int $depth, compare it with a MAX_DEPTH constant and fail past it.',
            'FollowsAliasesUnbounded',
            $method,
        );
    }
}
