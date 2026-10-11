<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use QaConfig\PHPStan\Rules\StaticOnlyClassConstructorRule;

/**
 * @internal
 *
 * @extends RuleTestCase<StaticOnlyClassConstructorRule>
 */
#[CoversNothing]
#[Large]
final class StaticOnlyClassConstructorRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/Defence/StaticOnlyClass';

    public function testItFlagsAClassWithOnlyStaticMembersAndNoConstructor(): void
    {
        $this->analyse([self::FIXTURES . '/MissingConstructor.php'], [
            [$this->message('StaticMethodsOnly'), 10],
            [$this->message('ConstantsOnly'), 21],
            [$this->message('MixedStaticMembers'), 29],
        ]);
    }

    public function testItAcceptsGuardedClassesInstantiableClassesAndShapesItCannotJudge(): void
    {
        $this->analyse([self::FIXTURES . '/NotReported.php'], []);
    }

    protected function getRule(): Rule
    {
        return new StaticOnlyClassConstructorRule();
    }

    private function message(string $class): string
    {
        return \sprintf(
            '%s has only static members but declares no constructor, so `new %s()` builds a useless instance. Add `private function __construct()` as a guard.',
            $class,
            $class,
        );
    }
}
