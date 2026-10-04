<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use QaConfig\PHPStan\Rules\RecursionThroughNativeCallbackRule;

/**
 * @internal
 *
 * @extends RuleTestCase<RecursionThroughNativeCallbackRule>
 */
#[CoversNothing]
final class RecursionThroughNativeCallbackRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/Defence/NativeCallback';

    public function testItFlagsRecursionThroughAClosureAFirstClassCallableAndAMutualCycle(): void
    {
        $this->analyse([self::FIXTURES . '/RecursesThroughCallback.php'], [
            [$this->message('RecursesThroughCallback', 'equals', 'array_all'), 18],
            [$this->message('RecursesThroughCallback', 'mapFirstClass', 'array_map'), 28],
            [$this->message('RecursesThroughCallback', 'mutualA', 'array_map'), 38],
        ]);
    }

    public function testItLeavesLoopsAndCallbacksThatDoNotRecurseAlone(): void
    {
        $this->analyse([self::FIXTURES . '/RecursesWithoutCallback.php'], []);
    }

    protected function getRule(): Rule
    {
        return new RecursionThroughNativeCallbackRule(self::createReflectionProvider());
    }

    private function message(string $class, string $method, string $native): string
    {
        return \sprintf(
            '%s::%s() recurses through a callback given to native %s(): each level keeps a native stack frame, which runs out after a few thousand levels. Use an indexed loop instead.',
            $class,
            $method,
            $native,
        );
    }
}
