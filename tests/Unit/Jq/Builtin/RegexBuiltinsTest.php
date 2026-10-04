<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin;

use Closure;
use LTS\PhpXq\Jq\Builtin\RegexBuiltins;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\InputProviderInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex\FakeFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RegexBuiltinsTest extends TestCase
{
    public function testRegistersTheDocumentedBuiltins(): void
    {
        $registry = self::registry();

        foreach (['_match_impl/3', 'test/1', 'test/2', 'split/2', 'scan/1', 'scan/2', 'sub/2', 'sub/3', 'gsub/2', 'gsub/3'] as $signature) {
            [$name, $arity] = explode('/', $signature);
            self::assertNotNull($registry->lookup($name, (int)$arity), $signature);
        }

        self::assertStringContainsString('def match(re; mode)', $registry->prelude());
        self::assertStringContainsString('def splits($re; flags)', $registry->prelude());
        self::assertStringContainsString('def capture($val)', $registry->prelude());
    }

    public function testMatchReportsOffsetsAndCapturesInCodepoints(): void
    {
        $matches = self::match('ā bar with a combining codepoint U+0304', 'bar');
        self::assertSame([['offset' => 2, 'length' => 3, 'string' => 'bar', 'captures' => []]], $matches);

        $combining = self::match("a\u{0304} bar", 'bar');
        self::assertSame(3, $combining[0]['offset']);

        $combining = self::match("a b\u{0304}ar", "b\u{0304}ar");
        self::assertSame(['offset' => 2, 'length' => 4, 'string' => "b\u{0304}ar", 'captures' => []], $combining[0]);
    }

    public function testMatchWordBoundaryTreatsCombiningMarksAsWordCharacters(): void
    {
        $matches = self::match("a\u{0304} two-codepoint grapheme", '.+?\b');

        self::assertSame(2, $matches[0]['length']);
        self::assertSame("a\u{0304}", $matches[0]['string']);
    }

    public function testGlobalMatchWithCapturingGroupNotParticipating(): void
    {
        $matches = self::match('foo bar foo foo  foo', 'foo (?<bar123>bar)? foo', 'ig');

        self::assertSame(
            [
                ['offset' => 0, 'length' => 11, 'string' => 'foo bar foo', 'captures' => [['offset' => 4, 'length' => 3, 'string' => 'bar', 'name' => 'bar123']]],
                ['offset' => 12, 'length' => 8, 'string' => 'foo  foo', 'captures' => [['offset' => -1, 'string' => null, 'length' => 0, 'name' => 'bar123']]],
            ],
            $matches,
        );
    }

    public function testEmptyMatchesIterateOnePerPosition(): void
    {
        self::assertCount(4, self::match('abc', '( )*', 'g'));
        self::assertSame([], self::match('abc', '( )*', 'gn'));
        self::assertCount(3, self::match('ab', '', 'g'));
        self::assertSame([['offset' => 1, 'length' => 0, 'string' => '', 'captures' => []]], self::match('qux', '(?=u)', 'g'));
    }

    public function testEmptyCapturedGroupHasOffsetAndEmptyString(): void
    {
        $matches = self::match('c', '(?<x>a?)?b?');

        self::assertSame([['offset' => 0, 'length' => 0, 'string' => '', 'captures' => [['offset' => 0, 'string' => '', 'length' => 0, 'name' => 'x']]]], $matches);
    }

    public function testCaseInsensitiveDoesNotFoldAccents(): void
    {
        self::assertSame([], self::match('āáàä', 'a', 'gi'));
    }

    public function testExtendedFlagIgnoresWhitespaceAndComments(): void
    {
        self::assertTrue($this->test('xabcd', 'a b c # spaces are ignored', 'ix'));
        self::assertTrue($this->test('ABC', 'a b c # spaces are ignored', 'ix'));
    }

    public function testDotAllFlagMakesDotMatchNewline(): void
    {
        self::assertFalse($this->test("a\nb", 'a.b'));
        self::assertTrue($this->test("a\nb", 'a.b', 'p'));
    }

    public function testNamedAndNumberedGroupsBothCapture(): void
    {
        $matches = self::match('ab', '(?<first>a)(b)');

        $captures = $matches[0]['captures'];
        self::assertIsArray($captures);
        self::assertSame(['first', null], array_column($captures, 'name'));
    }

    public function testHexEscapeIsALiteralLetterInOnigurumasPerlSyntax(): void
    {
        self::assertTrue($this->test('hh', '^\h+$'));
        self::assertFalse($this->test('ff', '^\h+$'));
        self::assertFalse($this->test('a b', '\h'));
    }

    public function testPosixPropertyNames(): void
    {
        self::assertTrue($this->test('a1', '\p{Digit}'));
        self::assertFalse($this->test('ab', '\p{Digit}'));
        self::assertTrue($this->test('ab', '^\P{Digit}+$'));
        self::assertTrue($this->test("x\ty", 'x\p{Blank}y'));
        self::assertTrue($this->test('a!', '\p{Punct}'));
        self::assertTrue($this->test('é', '\p{Alpha}'));
    }

    public function testCaseInsensitivePosixCasedClasses(): void
    {
        self::assertTrue($this->test('a', '[[:upper:]]', 'i'));
        self::assertFalse($this->test('a', '[[:upper:]]'));
        self::assertTrue($this->test('A', '\p{Lower}', 'i'));
    }

    public function testIgnoreEmptyBacktracksIntoANonEmptyAlternative(): void
    {
        $matches = self::match('abc', '|a', 'n');

        self::assertSame([['offset' => 0, 'length' => 1, 'string' => 'a', 'captures' => []]], $matches);
        self::assertTrue($this->test('abc', '|a', 'n'));
        self::assertFalse($this->test('xbc', '|a', 'n'));
    }

    public function testLongestMatchModifier(): void
    {
        $matches = self::match("line1\nline2", '\D+', 'l');
        self::assertSame(5, $matches[0]['offset']);
        self::assertSame("\nline", $matches[0]['string']);

        $first = self::match('ab12 cd34', '\D+');
        self::assertSame(0, $first[0]['offset']);

        $global = self::match('ab12 cd34', '\D+', 'gl');
        self::assertSame([4], array_column($global, 'offset'));

        $ties = self::match('ab12 cd34', '\d+|ab', 'gl');
        self::assertSame(['ab', '12', '34'], array_column($ties, 'string'));
    }

    public function testTestWithPatternAndFlagsArray(): void
    {
        $test = self::value('test', 1);

        self::assertTrue($test->call(self::context(), 'foo', ['FOO', 'i']));
        self::assertTrue($test->call(self::context(), 'foo', 'foo'));
        self::assertFalse($test->call(self::context(), 'foo', ['bar']));
    }

    public function testTestWithEmptyArrayIsAnError(): void
    {
        self::assertSame('array not a string or array', $this->errorOf(static fn (): mixed => self::value('test', 1)->call(self::context(), 'a', [])));
        self::assertSame('number not a string or array', $this->errorOf(static fn (): mixed => self::value('test', 1)->call(self::context(), 'a', 1)));
    }

    public function testErrorsForBadArguments(): void
    {
        self::assertSame('number (1) cannot be matched, as it is not a string', $this->errorOf(static fn (): mixed => self::match(1, 'a')));
        self::assertSame('number (1) is not a string', $this->errorOf(static fn (): mixed => self::match('a', 1)));
        self::assertSame('number (2) is not a string', $this->errorOf(static fn (): mixed => self::match('a', 'a', 2)));
        self::assertSame('gq is not a valid modifier string', $this->errorOf(static fn (): mixed => self::match('a', 'a', 'gq')));
    }

    public function testInvalidRegexMessagesMirrorOniguruma(): void
    {
        self::assertSame('( (at offset 0) is not a valid regex: end pattern with unmatched parenthesis', $this->errorOf(static fn (): mixed => self::match('a', '(')));
        self::assertSame('*a (at offset 0) is not a valid regex: target of repeat operator is not specified', $this->errorOf(static fn (): mixed => self::match('a', '*a')));
        self::assertSame('[a (at offset 0) is not a valid regex: premature end of char-class', $this->errorOf(static fn (): mixed => self::match('a', '[a')));
    }

    public function testMatchImplTestModeReturnsBool(): void
    {
        $impl = self::value('_match_impl', 3);

        self::assertTrue($impl->call(self::context(), 'abc', 'b', null, true));
        self::assertFalse($impl->call(self::context(), 'abc', 'x', null, true));
        self::assertIsArray($impl->call(self::context(), 'abc', 'b', null, false));
    }

    public function testSplitByRegex(): void
    {
        self::assertSame([['ab', 'cd', 'ef']], self::stream('split', 'ab,cd, ef', FakeFilter::yielding(', *'), FakeFilter::yielding(null)));
        self::assertSame([['', 'a', 'b', '']], self::stream('split', 'ab', FakeFilter::yielding(''), FakeFilter::yielding(null)));
        self::assertSame([['ab']], self::stream('split', 'ab', FakeFilter::yielding('c'), FakeFilter::yielding(null)));
        self::assertSame([['', 'b', 'BB', 'b', '']], self::stream('split', 'abAABBabA', FakeFilter::yielding('a+'), FakeFilter::yielding('i')));
    }

    public function testSplitFlagsMustBeStringOrNull(): void
    {
        self::assertSame(
            'string ("g") and number (1) cannot be added',
            $this->errorOf(static fn (): mixed => self::stream('split', 'a', FakeFilter::yielding('a'), FakeFilter::yielding(1))),
        );
    }

    public function testScanEmitsMatchesOrCaptureLists(): void
    {
        self::assertSame(['c', 'c'], self::stream('scan', 'abcdefabc', FakeFilter::yielding('c')));
        self::assertSame([['a', 'b'], ['aa', 'bb'], ['aaa', 'bbb']], self::stream('scan', 'abaabbaaabbb', FakeFilter::yielding('(a+)(b+)')));
        self::assertSame(['b', 'BBB', 'bbb'], self::stream('scan', 'abcABBBCabbbc', FakeFilter::yielding('b+'), FakeFilter::yielding('i')));
        self::assertSame(['bBb'], self::stream('scan', 'bBb', FakeFilter::yielding('b+'), FakeFilter::yielding('i')));
        self::assertSame([[null]], self::stream('scan', 'b', FakeFilter::yielding('(a)?b')));
    }

    public function testScanWithEmptyMatches(): void
    {
        self::assertSame(['', '', ''], self::stream('scan', 'ab', FakeFilter::yielding('')));
    }

    public function testSubReplacesTheFirstMatch(): void
    {
        self::assertSame(['a,b:c, d, e,f'], self::stream('sub', 'a,b, c, d, e,f', FakeFilter::yielding(', '), FakeFilter::yielding(':')));
        self::assertSame([':a,b, c, d, e,f, '], self::stream('sub', ', a,b, c, d, e,f, ', FakeFilter::yielding(', '), FakeFilter::yielding(':')));
    }

    public function testSubReplacementSeesNamedCaptures(): void
    {
        $replacement = FakeFilter::onCaptures(static fn (JsonObject $captures): array => ['Head=' . self::text($captures, 'head') . ' Tail=']);

        self::assertSame(['Head=a Tail=bcdef'], self::stream('sub', 'abcdef', FakeFilter::yielding('^(?<head>.)'), $replacement));
    }

    public function testGsubReplacesEveryMatch(): void
    {
        self::assertSame(['a,b:c:d:e,f'], self::stream('gsub', 'a,b, c, d, e,f', FakeFilter::yielding(', '), FakeFilter::yielding(':')));
        self::assertSame(['bbbbb'], self::stream('gsub', 'aaaaa', FakeFilter::yielding('a'), FakeFilter::yielding('b')));
        self::assertSame(['quux'], self::stream('gsub', 'qux', FakeFilter::yielding('(?=u)'), FakeFilter::yielding('u')));
    }

    public function testGsubEmptyMatchRules(): void
    {
        self::assertSame(['a'], self::stream('gsub', '', FakeFilter::yielding(''), FakeFilter::yielding('a'), FakeFilter::yielding('g')));
        self::assertSame(['a'], self::stream('gsub', 'a', FakeFilter::yielding('^'), FakeFilter::yielding(''), FakeFilter::yielding('g')));
        self::assertSame(['aaa'], self::stream('gsub', 'a', FakeFilter::yielding(''), FakeFilter::yielding('a'), FakeFilter::yielding('g')));
        self::assertSame(['aa'], self::stream('gsub', 'a', FakeFilter::yielding('$'), FakeFilter::yielding('a'), FakeFilter::yielding('g')));
        self::assertSame(['a'], self::stream('gsub', '', FakeFilter::yielding('^'), FakeFilter::yielding('a')));
        self::assertSame([''], self::stream('gsub', '', FakeFilter::yielding('(.*)'), FakeFilter::yielding(''), FakeFilter::yielding('x')));
    }

    public function testGsubGreedyAndLazyAnchoredPatterns(): void
    {
        self::assertSame(['b'], self::stream('gsub', 'aaa', FakeFilter::yielding('^.*a'), FakeFilter::yielding('b')));
        self::assertSame(['baa'], self::stream('gsub', 'aaa', FakeFilter::yielding('^.*?a'), FakeFilter::yielding('b')));
    }

    public function testGsubWithNamedCapturesAndUnicode(): void
    {
        $upper = FakeFilter::onCaptures(static fn (JsonObject $captures): array => ['+' . self::text($captures, 'x') . '-']);

        self::assertSame(['+A-+a-'], self::stream('gsub', 'Abcabc', FakeFilter::yielding('(?<x>.)[^a]*'), $upper));
        self::assertSame(['’!'], self::stream('sub', '’', FakeFilter::yielding('(?<x>.)'), FakeFilter::onCaptures(static fn (JsonObject $captures): array => [self::text($captures, 'x') . '!'])));
    }

    public function testGenerativeReplacementsAreAppliedInLockstep(): void
    {
        $replacement = FakeFilter::onCaptures(static fn (JsonObject $captures): array => [strtoupper(self::text($captures, 'a')), strtolower(self::text($captures, 'a')), 'c']);

        self::assertSame(['AB', 'aB', 'cB'], self::stream('sub', 'aB', FakeFilter::yielding('(?<a>.)'), $replacement));
        self::assertSame(['AB', 'ab', 'cc'], self::stream('gsub', 'aB', FakeFilter::yielding('(?<a>.)'), $replacement));
        self::assertSame(['b', 'c'], self::stream('gsub', 'a', FakeFilter::yielding('a'), FakeFilter::yielding('b', 'c')));
    }

    public function testSubWithNoMatchReturnsTheInput(): void
    {
        self::assertSame(['abc'], self::stream('sub', 'abc', FakeFilter::yielding('x'), FakeFilter::yielding('y')));
        self::assertSame(['abc'], self::stream('sub', 'abc', FakeFilter::yielding('b'), FakeFilter::from(static fn (): array => [])));
    }

    public function testSubReplacementMustBeAString(): void
    {
        self::assertSame(
            'string ("a") and number (1) cannot be added',
            $this->errorOf(static fn (): mixed => self::stream('sub', 'ab', FakeFilter::yielding('b'), FakeFilter::yielding(1))),
        );
    }

    public function testSubFlagsAndGlobalSuffix(): void
    {
        self::assertSame(['xxxb'], self::stream('gsub', 'aAab', FakeFilter::yielding('a'), FakeFilter::yielding('x'), FakeFilter::yielding('i')));
        self::assertSame(['xAab'], self::stream('sub', 'aAab', FakeFilter::yielding('a'), FakeFilter::yielding('x'), FakeFilter::yielding(null)));
        self::assertSame(['xAxb'], self::stream('gsub', 'aAab', FakeFilter::yielding('a'), FakeFilter::yielding('x'), FakeFilter::yielding(null)));
    }

    public function testSubParametersExpandWithTheFirstOutermost(): void
    {
        $outputs = self::stream('sub', 'aB', FakeFilter::yielding('a', 'b'), FakeFilter::yielding('X'), FakeFilter::yielding(null, 'i'));

        self::assertSame(['XB', 'XB', 'aB', 'aX'], $outputs);
    }

    #[DataProvider('nonAsciiOffsets')]
    public function testOffsetsAreCodepoints(string $subject, string $pattern, ?string $flags, int $expected): void
    {
        self::assertSame($expected, self::match($subject, $pattern, $flags)[0]['offset']);
    }

    /**
     * @return iterable<string, array{string, string, ?string, int}>
     */
    public static function nonAsciiOffsets(): iterable
    {
        yield 'two byte' => ['éa', 'a', null, 1];
        yield 'three byte' => ['€€a', 'a', null, 2];
        yield 'four byte' => ["\u{1F600}a", 'a', null, 1];
        yield 'ascii' => ['xxa', 'a', null, 2];
    }

    private static function registry(): BuiltinRegistryInterface
    {
        $registry = new DefaultBuiltinRegistry();
        new RegexBuiltins()->registerInto($registry);

        return $registry;
    }

    private static function value(string $name, int $arity): ValueBuiltinInterface
    {
        $builtin = self::registry()->lookup($name, $arity);
        self::assertInstanceOf(ValueBuiltinInterface::class, $builtin);

        return $builtin;
    }

    /**
     * @return list<mixed>
     */
    private static function stream(string $name, mixed $input, FilterInterface ...$args): array
    {
        $builtin = self::registry()->lookup($name, \count($args));
        self::assertInstanceOf(StreamBuiltinInterface::class, $builtin);

        $outputs = [];
        $builtin->run(self::context(), $input, static function (mixed $value) use (&$outputs): void {
            $outputs[] = self::plain($value);
        }, ...$args);

        return $outputs;
    }

    /**
     * @return list<array<mixed, mixed>>
     */
    private static function match(mixed $input, mixed $pattern, mixed $flags = null): array
    {
        $result = self::value('_match_impl', 3)->call(self::context(), $input, $pattern, $flags, false);
        self::assertIsArray($result);

        $plain = self::plain($result);
        self::assertIsArray($plain);

        $matches = [];
        foreach ($plain as $match) {
            self::assertIsArray($match);
            $matches[] = $match;
        }

        return $matches;
    }

    private static function text(JsonObject $captures, string $name): string
    {
        $value = $captures->get($name);
        self::assertIsString($value);

        return $value;
    }

    private function test(mixed $input, mixed $pattern, mixed $flags = null): bool
    {
        return true === self::value('test', 2)->call(self::context(), $input, $pattern, $flags);
    }

    private static function plain(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            $value = $value->toArray();
        }

        if (!\is_array($value)) {
            return $value;
        }

        $plain = [];
        foreach ($value as $key => $item) {
            $plain[$key] = self::plain($item);
        }

        return $plain;
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

    private static function context(): RuntimeContextInterface
    {
        return new class implements RuntimeContextInterface {
            public function inputs(): InputProviderInterface
            {
                throw new JqException('no inputs');
            }

            public function globals(): array
            {
                return [];
            }

            public function inputFilename(): ?string
            {
                return null;
            }

            public function libraryPaths(): array
            {
                return [];
            }

            public function debug(mixed $value): void
            {
            }

            public function writeStderr(mixed $value): void
            {
            }

            public function now(): float
            {
                return 0.0;
            }
        };
    }
}
