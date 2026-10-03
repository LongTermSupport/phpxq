<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Builtin\Regex\RegexTranslator;
use LTS\PhpXq\Jq\Runtime\JqException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RegexTranslatorTest extends TestCase
{
    public function testPlainPatternIsCopiedAndGroupsAreCounted(): void
    {
        $translated = RegexTranslator::translate('a(b)(?:c)(?<n>d)', false, false);

        self::assertSame('a(b)(?:c)(?<n>d)', $translated->pcre);
        self::assertSame([null, 'n'], $translated->groupNames);
        self::assertFalse($translated->usesWordEscapes);
    }

    public function testDelimiterSlashIsEscaped(): void
    {
        self::assertSame('a\/b[\/]', RegexTranslator::translate('a/b[/]', false, false)->pcre);
    }

    public function testEscapedSlashIsNotDoubled(): void
    {
        self::assertSame('a\/b', RegexTranslator::translate('a\/b', false, false)->pcre);
    }

    public function testLookaroundsAreNotCaptures(): void
    {
        $translated = RegexTranslator::translate('(?=a)(?!b)(?<=c)(?<!d)(?>e)(?i)(?i:f)', false, false);

        self::assertSame([], $translated->groupNames);
    }

    public function testQuotedNamesAndPythonStyleNames(): void
    {
        $translated = RegexTranslator::translate("(?'a'x)(?P<b>y)", false, false);

        self::assertSame(['a', 'b'], $translated->groupNames);
    }

    public function testHexEscapesAreLiteralLettersLikeInOnigurumasPerlSyntax(): void
    {
        self::assertSame('h+H', RegexTranslator::translate('\h+\H', false, false)->pcre);
        self::assertSame('[hz]', RegexTranslator::translate('[\hz]', false, false)->pcre);
    }

    public function testPosixPropertyNamesPcreLacksAreMapped(): void
    {
        self::assertSame('[\p{Nd}]+', RegexTranslator::translate('\p{Digit}+', false, false)->pcre);
        self::assertSame('[^\p{Nd}]', RegexTranslator::translate('\P{digit}', false, false)->pcre);
        self::assertSame('[^\p{Nd}]', RegexTranslator::translate('\p{^Digit}', false, false)->pcre);
        self::assertSame('[\p{Nd}]', RegexTranslator::translate('\P{^Digit}', false, false)->pcre);
        self::assertSame('[\p{Nd}x]', RegexTranslator::translate('[\p{Digit}x]', false, false)->pcre);
        self::assertSame('[\P{Nd}x]', RegexTranslator::translate('[\P{Digit}x]', false, false)->pcre);
        self::assertSame('[\H]', RegexTranslator::translate('[\P{Blank}]', false, false)->pcre);
        self::assertSame('[0-9a-fA-F]', RegexTranslator::translate('\p{XDigit}', false, false)->pcre);
        self::assertSame('[\x00-\x7f]', RegexTranslator::translate('\p{ASCII}', false, false)->pcre);
        self::assertSame('[\p{Pc}]', str_replace('\p{L}\p{M}\p{Nd}\p{Nl}', '', RegexTranslator::translate('\p{Word}', false, false)->pcre));
    }

    public function testPropertyNamesAreNormalisedAndOthersAreLeftToPcre(): void
    {
        self::assertSame('[\p{Nd}]', RegexTranslator::translate('\p{ D_i-g it }', false, false)->pcre);
        self::assertSame('\p{L}+', RegexTranslator::translate('\p{L}+', false, false)->pcre);
        self::assertSame('\p{Greek}', RegexTranslator::translate('\p{Greek}', false, false)->pcre);
        self::assertSame('\p{', RegexTranslator::translate('\p{', false, false)->pcre);
    }

    public function testCasedClassesWithIgnoreCase(): void
    {
        self::assertSame('[\p{Lu}\p{Ll}\p{Lt}]', RegexTranslator::translate('[[:upper:]]', false, false, true)->pcre);
        self::assertSame('[[:upper:]]', RegexTranslator::translate('[[:upper:]]', false, false, false)->pcre);
        self::assertSame('[\p{Lu}\p{Ll}\p{Lt}]', RegexTranslator::translate('\p{Lower}', false, false, true)->pcre);
    }

    public function testNegatedPropertyWithoutAClassFormInsideAClassIsRejected(): void
    {
        self::assertSame(
            '[\P{Alnum}] (at offset 0) is not a valid regex: negated property inside a character class is not supported',
            $this->errorOf('[\P{Alnum}]'),
        );
    }

    public function testWordEscapesAreTranslatedOnlyInUnicodeWordMode(): void
    {
        $native  = RegexTranslator::translate('\w\b', false, false);
        $unicode = RegexTranslator::translate('\w\b', false, true);

        self::assertSame('\w\b', $native->pcre);
        self::assertTrue($native->usesWordEscapes);
        self::assertStringContainsString('\p{M}', $unicode->pcre);
        self::assertStringContainsString('(?<=', $unicode->pcre);
    }

    public function testBackspaceInsideClassIsKept(): void
    {
        self::assertSame('[\b]', RegexTranslator::translate('[\b]', false, true)->pcre);
    }

    public function testCountedQuantifierWithoutLowerBound(): void
    {
        self::assertSame('a{0,3}', RegexTranslator::translate('a{,3}', false, false)->pcre);
        self::assertSame('a{2,3}\x{41}', RegexTranslator::translate('a{2,3}\x{41}', false, false)->pcre);
    }

    public function testExtendedModeCommentsAreNotParsed(): void
    {
        $translated = RegexTranslator::translate("a # (b / c\n(d)", true, false);

        self::assertSame("a # (b \\/ c\n(d)", $translated->pcre);
        self::assertSame([null], $translated->groupNames);
    }

    public function testInlineExtendedFlagEnablesComments(): void
    {
        $translated = RegexTranslator::translate("(?x)a # (\n(d)", false, false);

        self::assertSame([null], $translated->groupNames);
    }

    public function testPosixBracketInsideClass(): void
    {
        self::assertSame('[[:alpha:]\/]', RegexTranslator::translate('[[:alpha:]/]', false, false)->pcre);
    }

    public function testLeadingClosingBracketInClassIsLiteral(): void
    {
        $translated = RegexTranslator::translate('[]a](b)', false, false);

        self::assertSame([null], $translated->groupNames);
    }

    public function testQuotedRegionIsCopied(): void
    {
        $translated = RegexTranslator::translate('\Q(a/\E(b)', false, false);

        self::assertSame([null], $translated->groupNames);
        self::assertSame('\Q(a\E\/\Q\E(b)', $translated->pcre);
    }

    public function testDuplicateNamesNeedTheDuplicateFlag(): void
    {
        $translated = RegexTranslator::translate('(?<a>x)|(?<a>y)', false, false);

        self::assertSame('(?J)(?<a>x)|(?<a>y)', $translated->pcre);
    }

    public function testTrailingBackslashIsAnError(): void
    {
        self::assertSame('a\ (at offset 0) is not a valid regex: end pattern at escape', $this->errorOf('a\\'));
    }

    public function testEmptyGroupNameIsAnError(): void
    {
        self::assertSame('(?<>a) (at offset 0) is not a valid regex: group name is empty', $this->errorOf('(?<>a)'));
    }

    private function errorOf(string $pattern): string
    {
        try {
            RegexTranslator::translate($pattern, false, false);
        } catch (JqException $jqException) {
            return $jqException->getMessage();
        }

        self::fail('Expected a JqException');
    }
}
