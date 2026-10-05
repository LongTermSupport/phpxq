<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\StringDiscriminator;

enum FixtureKindEnum: string
{
    case Head = 'head';

    case Foot = 'foot';
}

/**
 * Comparisons the rule accepts: one literal per subject, constants and enum cases, punctuation, the empty
 * string, expressions that are not variables, and literals compared with each other.
 */
final class ComparesOneNameOrNoNames
{
    private const string HEAD = 'head';

    private const string FOOT = 'foot';

    public function oneLiteralOnly(string $kind): bool
    {
        return 'head' === $kind || 'head' === $kind;
    }

    public function oneLiteralPerSubject(string $a, string $b): bool
    {
        return 'head' === $a && 'foot' === $b;
    }

    public function constantsAndEnumCases(string $kind, FixtureKindEnum $enum): bool
    {
        return self::HEAD === $kind || self::FOOT === $kind || FixtureKindEnum::Head === $enum || FixtureKindEnum::Foot === $enum;
    }

    public function punctuationAndEmptyAreNotNames(string $char): bool
    {
        return '.' === $char || ',' === $char || '' === $char || '[]' === $char || '1' === $char || ' x' === $char;
    }

    public function callsAndArraysAreNotSubjects(string $text): bool
    {
        return 'head' === strtolower($text) || 'foot' === strtolower($text) || 'a' === $text[0] || 'b' === $text[0];
    }

    public function matchOnTrueAndWithoutLiteralArms(string $kind): int
    {
        return match (true) {
            'head' === $kind => 1,
            default          => 0,
        };
    }

    public function switchWithOnlyDefaultAndVariableCases(string $kind, string $other): int
    {
        switch ($kind) {
            case $other:
                return 1;

            default:
                return 0;
        }
    }

    public function literalsAgainstLiterals(): bool
    {
        return 'head' === 'foot' || 'a' === 'b';
    }

    public function otherComparisonsAreNotDiscriminators(string $kind): bool
    {
        return 'head' < $kind || 'foot' <=> $kind || 'x' === 'line' . $kind || 'head' === $kind . 'x' || 'foot' === $kind . 'y';
    }

    public function inArrayWithOneLiteralOrNonLiterals(string $kind, array $names): bool
    {
        return \in_array($kind, ['head'], true) || \in_array($kind, $names, true) || \in_array($kind, [self::HEAD, self::FOOT], true);
    }
}
