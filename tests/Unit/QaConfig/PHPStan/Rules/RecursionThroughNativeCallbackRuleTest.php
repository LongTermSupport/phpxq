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

    private const string CALLBACK_CLASS = 'RecursesThroughCallback';

    private const string MAP = 'array_map';

    public function testItFlagsRecursionThroughAClosureAFirstClassCallableAndAMutualCycle(): void
    {
        $this->analyse([self::FIXTURES . '/RecursesThroughCallback.php'], [
            [$this->message(self::CALLBACK_CLASS, 'equals', 'array_all'), 18],
            [$this->message(self::CALLBACK_CLASS, 'mapFirstClass', self::MAP), 28],
            [$this->message(self::CALLBACK_CLASS, 'mutualA', self::MAP), 38],
        ]);
    }

    public function testItLeavesLoopsAndCallbacksThatDoNotRecurseAlone(): void
    {
        $this->analyse([self::FIXTURES . '/RecursesWithoutCallback.php'], []);
    }

    public function testItFlagsNamedArgumentsStaticClosuresAndEnums(): void
    {
        $this->analyse([self::FIXTURES . '/RecursesInOddSyntax.php'], [
            [$this->message('RecursesInOddSyntax', 'namedArguments', self::MAP), 19],
            [$this->message('RecursesInOddSyntax', 'staticClosure', self::MAP), 29],
            [$this->message('RecursiveEnum', 'walk', self::MAP), 44],
        ]);
    }

    public function testItSurvivesFirstClassNativesSpreadsDynamicCallablesInterfacesAndTraits(): void
    {
        $this->analyse([self::FIXTURES . '/OddSyntaxNotRecursing.php'], []);
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
