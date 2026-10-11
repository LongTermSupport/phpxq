<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use Closure;
use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yq\Runtime\Compare;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\GoRegex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * When the regex engine gives up (backtracking limit, malformed UTF-8 subject) yq must raise an error, as the
 * jq side does, instead of answering "no match": `test` false, `match` empty and `sub` unchanged were all
 * silently wrong.
 *
 * @internal
 */
#[CoversClass(GoRegex::class)]
#[CoversClass(Compare::class)]
#[Small]
final class GoRegexEngineErrorTest extends TestCase
{
    /** Exponential backtracking: 2^40 ways to split the run of `a` before the `c` alternative is tried. */
    private const string CATASTROPHIC = '(a+)+b|c';

    /** A subject the UTF-8 mode of PCRE refuses. */
    private const string MALFORMED = "a\xff";

    /** What PCRE reports for that subject. */
    private const string MALFORMED_MESSAGE = 'Malformed UTF-8';

    /** What PCRE reports when the backtracking limit is hit, with or without JIT. */
    private const string LIMIT_MESSAGE = 'limit';

    public function testTestRaisesWhenTheBacktrackingLimitIsHit(): void
    {
        $this->assertEngineError(static fn (): bool => GoRegex::test(GoRegex::compile(self::CATASTROPHIC), str_repeat('a', 40) . 'c'), self::LIMIT_MESSAGE);
    }

    public function testTestRaisesOnAMalformedSubject(): void
    {
        $this->assertEngineError(static fn (): bool => GoRegex::test(GoRegex::compile('a'), self::MALFORMED), self::MALFORMED_MESSAGE);
    }

    public function testAFirstMatchRaisesOnAMalformedSubject(): void
    {
        $this->assertEngineError(static fn (): array => GoRegex::matches(GoRegex::compile('a'), self::MALFORMED, false), self::MALFORMED_MESSAGE);
    }

    public function testAllMatchesRaiseOnAMalformedSubject(): void
    {
        $this->assertEngineError(static fn (): array => GoRegex::matches(GoRegex::compile('a'), self::MALFORMED, true), self::MALFORMED_MESSAGE);
    }

    public function testReplaceRaisesOnAMalformedSubject(): void
    {
        $this->assertEngineError(static fn (): string => GoRegex::replace(GoRegex::compile('a'), 'b', self::MALFORMED), self::MALFORMED_MESSAGE);
    }

    public function testAGlobRaisesOnAMalformedSubject(): void
    {
        $this->assertEngineError(static fn (): bool => Compare::glob(self::MALFORMED, 'a*'), self::MALFORMED_MESSAGE);
    }

    public function testWellFormedSubjectsStillMatch(): void
    {
        $regex = GoRegex::compile('b+');

        self::assertTrue(GoRegex::test($regex, 'abbc'));
        self::assertFalse(GoRegex::test($regex, 'ac'));
        self::assertSame('aXc', GoRegex::replace($regex, 'X', 'abbc'));
        self::assertCount(2, GoRegex::matches($regex, 'abcb', true));
        self::assertSame([], GoRegex::matches($regex, 'ac', false));
        self::assertTrue(Compare::glob('abc', 'a*'));
        self::assertFalse(Compare::glob('xbc', 'a*'));
    }

    public function testTheErrorReachesTheCommandLine(): void
    {
        $result = new CliRunner()->run(['yq', '-n', '"' . str_repeat('a', 40) . 'c" | test("' . self::CATASTROPHIC . '")']);

        self::assertSame([1, ''], [$result->exitCode, $result->stdout]);
        self::assertStringStartsWith('Error: ', $result->stderr);
    }

    /**
     * @param Closure(): mixed $call
     */
    private function assertEngineError(Closure $call, string $message): void
    {
        try {
            $call();
        } catch (EvaluationException $evaluationException) {
            self::assertStringContainsString($message, $evaluationException->getMessage());

            return;
        }

        self::fail('The regex engine error was swallowed.');
    }
}
