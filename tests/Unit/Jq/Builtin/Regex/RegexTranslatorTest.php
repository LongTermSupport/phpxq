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

    public function testHexDigitEscapes(): void
    {
        self::assertSame('[0-9a-fA-F]+[^0-9a-fA-F]', RegexTranslator::translate('\h+\H', false, false)->pcre);
        self::assertSame('[0-9a-fA-Fz]', RegexTranslator::translate('[\hz]', false, false)->pcre);
    }

    public function testNegatedHexEscapeInsideClassIsRejected(): void
    {
        self::assertSame(
            '[\H] (at offset 0) is not a valid regex: \H inside a character class is not supported',
            self::errorOf('[\H]'),
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
        self::assertSame('a\ (at offset 0) is not a valid regex: end pattern at escape', self::errorOf('a\\'));
    }

    public function testEmptyGroupNameIsAnError(): void
    {
        self::assertSame('(?<>a) (at offset 0) is not a valid regex: group name is empty', self::errorOf('(?<>a)'));
    }

    private static function errorOf(string $pattern): string
    {
        try {
            RegexTranslator::translate($pattern, false, false);
        } catch (JqException $exception) {
            return $exception->getMessage();
        }

        self::fail('Expected a JqException');
    }
}
