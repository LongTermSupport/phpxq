<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use Closure;
use LTS\PhpXq\Jq\Builtin\Regex\OnigRegex;
use LTS\PhpXq\Jq\Runtime\JqException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class OnigRegexTest extends TestCase
{
    public function testFlagsAreParsed(): void
    {
        $regex = OnigRegex::compile('a', 'gn');

        self::assertTrue($regex->global);
        self::assertTrue($regex->ignoreEmpty);
        self::assertFalse(OnigRegex::compile('a', null)->global);
        self::assertFalse(OnigRegex::compile('a', '')->ignoreEmpty);
    }

    public function testAcceptedButIneffectiveFlags(): void
    {
        $regex = OnigRegex::compile('a', 'sl');

        self::assertSame('/a/u', $regex->pcre(true));
    }

    public function testCaseExtendedAndDotAllFlagsReachPcre(): void
    {
        self::assertSame('/a b/uixs', OnigRegex::compile('a b', 'ixp')->pcre(true));
    }

    public function testUnknownModifierIsRejectedWithTheWholeString(): void
    {
        self::assertSame('gz is not a valid modifier string', $this->errorOf(static fn (): OnigRegex => OnigRegex::compile('a', 'gz')));
    }

    public function testGroupNamesAreExposed(): void
    {
        self::assertSame(['year', null, 'day'], OnigRegex::compile('(?<year>\d+)-(\d+)-(?<day>\d+)', null)->groupNames);
    }

    public function testCompilationIsCached(): void
    {
        self::assertSame(OnigRegex::compile('cached', 'i'), OnigRegex::compile('cached', 'i'));
        self::assertNotSame(OnigRegex::compile('cached', 'i'), OnigRegex::compile('cached', null));
    }

    public function testWordEscapesSelectAUnicodeVariantForNonAsciiSubjects(): void
    {
        $regex = OnigRegex::compile('\bfoo\w', null);

        self::assertSame('/\bfoo\w/u', $regex->pcre(true));
        self::assertStringContainsString('\p{M}', $regex->pcre(false));
        self::assertSame($regex->pcre(false), $regex->pcre(false));
    }

    public function testPatternWithoutWordEscapesIgnoresTheSubjectKind(): void
    {
        $regex = OnigRegex::compile('foo', null);

        self::assertSame($regex->pcre(true), $regex->pcre(false));
    }

    public function testCompileErrorsUseOnigurumaWording(): void
    {
        self::assertSame('(a (at offset 0) is not a valid regex: end pattern with unmatched parenthesis', $this->errorOf(static fn (): OnigRegex => OnigRegex::compile('(a', null)));
        self::assertSame('a) (at offset 0) is not a valid regex: unmatched close parenthesis', $this->errorOf(static fn (): OnigRegex => OnigRegex::compile('a)', null)));
        self::assertSame('+ (at offset 0) is not a valid regex: target of repeat operator is not specified', $this->errorOf(static fn (): OnigRegex => OnigRegex::compile('+', null)));
        self::assertSame('[z-a] (at offset 0) is not a valid regex: empty range in char class', $this->errorOf(static fn (): OnigRegex => OnigRegex::compile('[z-a]', null)));
        self::assertSame('\k<nope> (at offset 0) is not a valid regex: undefined name reference', $this->errorOf(static fn (): OnigRegex => OnigRegex::compile('\k<nope>', null)));
    }

    public function testCacheSurvivesOverflow(): void
    {
        for ($i = 0; $i < 600; ++$i) {
            OnigRegex::compile('overflow' . $i, null);
        }

        self::assertSame('/overflow0/u', OnigRegex::compile('overflow0', null)->pcre(true));
    }

    /**
     * @param Closure(): mixed $action
     */
    private function errorOf(Closure $action): string
    {
        try {
            $action();
        } catch (JqException $jqException) {
            return $jqException->getMessage();
        }

        self::fail('Expected a JqException');
    }
}
