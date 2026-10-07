<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Medium;
use QaConfig\PHPStan\Rules\StringDiscriminatorRule;

/**
 * @internal
 *
 * @extends RuleTestCase<StringDiscriminatorRule>
 */
#[CoversNothing]
#[Medium]
final class StringDiscriminatorRuleTest extends RuleTestCase
{
    private const string FIXTURE_NAMESPACE = 'LTS\PhpXq\Tests\Fixtures\Defence\StringDiscriminator';

    private const string FIXTURES = __DIR__ . '/../../../../Fixtures/Defence/StringDiscriminator';

    private const string KIND = '$kind';

    /** @var list<string> */
    private array $syntaxNamespaces = [];

    public function testItFlagsASubjectComparedWithTwoOrMoreDifferentNameLiterals(): void
    {
        $this->analyse([self::FIXTURES . '/ComparesNames.php'], $this->allFindings());
    }

    public function testItAcceptsOneLiteralPerSubjectConstantsEnumCasesPunctuationAndNonVariableSubjects(): void
    {
        $this->analyse([self::FIXTURES . '/ComparesOneNameOrNoNames.php'], []);
    }

    public function testItSurvivesDynamicNamesSpreadsFirstClassCallablesAndEmptyMatchesAndSwitches(): void
    {
        $this->analyse([self::FIXTURES . '/OddSyntaxNotReported.php'], []);
    }

    public function testItSkipsClassesInANamedSyntaxNamespace(): void
    {
        $this->syntaxNamespaces = [self::FIXTURE_NAMESPACE];

        $this->analyse([self::FIXTURES . '/ComparesNames.php'], []);
    }

    public function testItDoesNotSkipAClassThatOnlySharesTheNamePrefix(): void
    {
        $this->syntaxNamespaces = [self::FIXTURE_NAMESPACE . '\Compares'];

        $this->analyse([self::FIXTURES . '/ComparesNames.php'], $this->allFindings());
    }

    public function testItSkipsAClassNamedExactlyButStillJudgesItsAnonymousClassesByNamespace(): void
    {
        $this->syntaxNamespaces = [self::FIXTURE_NAMESPACE . '\ComparesNames'];

        $this->analyse([self::FIXTURES . '/ComparesNames.php'], [
            [$this->message(self::KIND, 'inside', ['a', 'b']), 87],
        ]);
    }

    protected function getRule(): Rule
    {
        return new StringDiscriminatorRule($this->syntaxNamespaces);
    }

    /**
     * @return list<array{string, int}>
     */
    private function allFindings(): array
    {
        return [
            [$this->message(self::KIND, 'identical', ['head', 'line']), 20],
            [$this->message(self::KIND, 'looseAndReversed', ['yes', 'no', 'maybe']), 29],
            [$this->message(self::KIND, 'inArrayAndComparison', ['head', 'foot', 'line']), 34],
            [$this->message(self::KIND, 'matchArms', ['head', 'foot', 'line']), 40],
            [$this->message(self::KIND, 'switchCases', ['head', 'foot']), 49],
            [$this->message('$this->mode', 'propertyAndStaticProperty', ['plain', 'rich']), 62],
            [$this->message('self::$format', 'propertyAndStaticProperty', ['json', 'yaml']), 62],
            [$this->message('$this->child->mode', 'nullsafeProperty', ['a', 'b']), 67],
            [$this->message(self::KIND, 'oneFindingPerSubjectAndMethod', ['head', 'foot', 'line']), 72],
            [$this->message('$other', 'oneFindingPerSubjectAndMethod', ['x1', 'x2']), 72],
            [$this->message(self::KIND, 'manyLiterals', ['a1', 'a2', 'a3', 'a4', 'a5', 'a6'], true), 77],
            [$this->message(self::KIND, 'inside', ['a', 'b']), 87],
            [$this->message(self::KIND, 'namedArgumentsToInArray', ['head', 'foot']), 94],
        ];
    }

    /**
     * @param list<string> $literals
     */
    private function message(string $subject, string $method, array $literals, bool $truncated = false): string
    {
        $shown = implode(', ', array_map(static fn (string $literal): string => "'" . $literal . "'", $literals));

        return \sprintf(
            '%s is told apart in %s() by comparing it with the string literals %s%s: a closed set of names written as strings. Declare a backed enum and resolve it once with tryFrom() at the boundary, or give each value a class constant.',
            $subject,
            $method,
            $shown,
            $truncated ? ', ...' : '',
        );
    }
}
