<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Builtin\Regex\RegexTranslator;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Support\CliRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Every part of a pattern that is copied verbatim escapes the `/` delimiter, so no pattern can end the PCRE
 * source early and leak PHP's "Unknown modifier" warning. `(*...)` is an Oniguruma callout, not a PCRE verb:
 * only `(*FAIL)`, which means the same in both, is passed on, and the rest fail as Oniguruma fails them.
 *
 * @internal
 */
#[CoversClass(RegexTranslator::class)]
#[Small]
final class RegexTranslatorDelimiterTest extends TestCase
{
    /**
     * @param list<?string> $names
     */
    #[DataProvider('translations')]
    public function testTranslation(string $source, string $expected, array $names): void
    {
        $translated = RegexTranslator::translate($source, false, false);

        self::assertSame($expected, $translated->pcre);
        self::assertSame($names, $translated->groupNames);
    }

    /**
     * @return iterable<string, array{string, string, list<?string>}>
     */
    public static function translations(): iterable
    {
        yield 'a slash in a condition'      => ['(?(/)a)', '(?(\/)a)', []];
        yield 'a slash after a condition'   => ['(a)(?(1)/b|c)', '(a)(?(1)\/b|c)', [null]];
        yield 'a slash in a group name'     => ['(?<a/b>a)', '(?<a\/b>a)', ['a/b']];
        yield 'a slash in a quoted name'    => ["(?'a/b'a)", "(?'a\\/b'a)", ['a/b']];
        yield 'the fail callout'            => ['a(*FAIL)|b', 'a(*FAIL)|b', []];
    }

    #[DataProvider('rejected')]
    public function testRejectedCallout(string $source, string $reason): void
    {
        try {
            RegexTranslator::translate($source, false, false);
        } catch (JqException $jqException) {
            self::assertSame($source . ' (at offset 0) is not a valid regex: ' . $reason, $jqException->getMessage());

            return;
        }

        self::fail('The callout was accepted: ' . $source);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejected(): iterable
    {
        yield 'a slash as the name'       => ['(*/)', RegexTranslator::INVALID_CALLOUT_NAME];
        yield 'a pcre start option'       => ['(*LIMIT_MATCH=1)a', RegexTranslator::INVALID_CALLOUT_NAME];
        yield 'an empty name'             => ['a(*)', RegexTranslator::INVALID_CALLOUT_NAME];
        yield 'a pcre verb'               => ['a(*ACCEPT)', RegexTranslator::UNDEFINED_CALLOUT_NAME];
        yield 'a pcre option name'        => ['(*UTF)a', RegexTranslator::UNDEFINED_CALLOUT_NAME];
        yield 'the short pcre fail'       => ['a(*F)', RegexTranslator::UNDEFINED_CALLOUT_NAME];
        yield 'an unterminated callout'   => ['a(*FAIL', RegexTranslator::END_PATTERN_IN_GROUP];
        yield 'fail with arguments'       => ['a(*FAIL{1})', RegexTranslator::INVALID_CALLOUT_ARG];
        yield 'an oniguruma builtin'      => ['a(*MAX{1})', \sprintf(RegexTranslator::UNSUPPORTED_CALLOUT, 'MAX')];
        yield 'an oniguruma builtin bare' => ['a(*MISMATCH)', \sprintf(RegexTranslator::UNSUPPORTED_CALLOUT, 'MISMATCH')];
    }

    public function testTheDelimiterNeverLeaksAPhpWarning(): void
    {
        $result = new CliRunner()->run(['jq', '-n', '"a" | test("(*/)")'], '');

        self::assertSame('jq: error (at <unknown>): (*/) (at offset 0) is not a valid regex: ' . RegexTranslator::INVALID_CALLOUT_NAME . "\n", $result->stderr);
        self::assertSame('', $result->stdout);
    }
}
