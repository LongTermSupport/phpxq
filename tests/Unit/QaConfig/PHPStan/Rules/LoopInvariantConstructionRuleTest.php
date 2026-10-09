<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use QaConfig\PHPStan\Rules\LoopInvariantConstructionRule;

/**
 * @internal
 *
 * @extends RuleTestCase<LoopInvariantConstructionRule>
 */
#[CoversNothing]
#[Large]
final class LoopInvariantConstructionRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/Defence/LoopInvariantConstruction';

    private const string REGISTRY = 'DefaultBuiltinRegistry';

    public function testItFlagsInvariantConstructionAndUncachedBuilderCallsInsideLoops(): void
    {
        $this->analyse([self::FIXTURES . '/ConstructsPerIteration.php'], [
            [$this->direct(self::REGISTRY), 22],
            [$this->direct('JsonEncoder'), 36],
            [$this->direct(self::REGISTRY), 49],
            [$this->helper('call', self::REGISTRY), 55],
        ]);
    }

    public function testItAcceptsHoistedMemoisedCheapAndIterationDependentConstruction(): void
    {
        $this->analyse([self::FIXTURES . '/BuildsOnceOrPerInput.php'], []);
    }

    public function testItFlagsNamedArgumentsStaticClosuresAndCallbacksGivenByName(): void
    {
        $this->analyse([self::FIXTURES . '/OddSyntaxConstruction.php'], [
            [$this->direct('JsonDecoder'), 21],
            [$this->direct(self::REGISTRY), 31],
            [$this->helper('build', self::REGISTRY), 42],
        ]);
    }

    public function testItSurvivesDynamicClassesSpreadsAnonymousClassesAndFirstClassCallables(): void
    {
        $this->analyse([self::FIXTURES . '/OddSyntaxNotReported.php'], []);
    }

    protected function getRule(): Rule
    {
        return new LoopInvariantConstructionRule();
    }

    private function direct(string $engine): string
    {
        return \sprintf('new %s(...) is built with arguments that do not change between iterations, so every pass of this loop builds it again. Build it once before the loop, or keep it in a property.', $engine);
    }

    private function helper(string $method, string $engine): string
    {
        return \sprintf('%s() builds a new %s on every call and this call is inside a loop. Keep the instance in a property (`??=`), or build it once before the loop.', $method, $engine);
    }
}
