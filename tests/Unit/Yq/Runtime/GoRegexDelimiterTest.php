<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Yq\Runtime\GoRegex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * The PCRE delimiter `~` is escaped only where the pattern has a bare `~`: an escaped `\~` is already a
 * literal tilde, and inside `\Q...\E` the tilde is literal text. Expected results are Go yq v4.54.1's.
 *
 * @internal
 */
#[CoversClass(GoRegex::class)]
#[Small]
final class GoRegexDelimiterTest extends TestCase
{
    private const string SUBJECT = 'a~b';

    #[DataProvider('patterns')]
    public function testTildeMatchesAsGoMatchesIt(string $pattern, string $subject, bool $expected): void
    {
        self::assertSame($expected, GoRegex::test(GoRegex::compile($pattern), $subject));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function patterns(): iterable
    {
        yield 'a bare tilde'                 => ['~', self::SUBJECT, true];
        yield 'an escaped tilde'             => ['\~', self::SUBJECT, true];
        yield 'a tilde in a class'           => ['[~]', self::SUBJECT, true];
        yield 'an escaped backslash, tilde'  => ['\\\~', 'a\~b', true];
        yield 'an escaped backslash, no tilde' => ['\\\~', self::SUBJECT, false];
        yield 'a tilde in a quoted run'      => ['\Q~\E', self::SUBJECT, true];
        yield 'an unterminated quoted run'   => ['\Qa~', self::SUBJECT, true];
        yield 'a quoted run is literal'      => ['\Q.~\E', self::SUBJECT, false];
    }

    public function testEscapedTildeIsReplaced(): void
    {
        self::assertSame('a-b', GoRegex::replace(GoRegex::compile('\~'), '-', self::SUBJECT));
    }
}
