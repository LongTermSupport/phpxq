<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use QaConfig\PHPStan\Rules\UnguardedAliasRecursionRule;

/**
 * @internal
 *
 * @extends RuleTestCase<UnguardedAliasRecursionRule>
 */
#[CoversNothing]
#[Large]
final class UnguardedAliasRecursionRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/Defence/AliasRecursion';

    private const string ODD_CLASS = 'OddSyntaxUnbounded';

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

    public function testItFlagsNullsafeNamedSpreadAndStaticCallsWithoutCrashing(): void
    {
        $this->analyse([self::FIXTURES . '/OddSyntaxUnbounded.php'], [
            [$this->message('viaNullsafe', self::ODD_CLASS), 15],
            [$this->message('namedArgument', self::ODD_CLASS), 26],
            [$this->message('spreadArgument', self::ODD_CLASS), 37],
            [$this->message('staticCall', self::ODD_CLASS), 48],
        ]);
    }

    public function testItSurvivesFirstClassCallablesDynamicNamesAnonymousClassesAndAbstractMethods(): void
    {
        $this->analyse([self::FIXTURES . '/OddSyntaxAccepted.php'], []);
    }

    protected function getRule(): Rule
    {
        return new UnguardedAliasRecursionRule();
    }

    private function message(string $method, string $class = 'FollowsAliasesUnbounded'): string
    {
        return \sprintf(
            '%s::%s() recurses into a node reached through an alias (NodeOps::deref, NodeTools::unwrap or aliasTarget) with no depth bound: a cyclic alias such as `&a [*a]` never ends. Take an int $depth, compare it with a MAX_DEPTH constant and fail past it.',
            $class,
            $method,
        );
    }
}
