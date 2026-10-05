<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yq\Format\Codec\HclScanner;
use LTS\PhpXq\Yq\Format\FormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class HclScannerTest extends TestCase
{
    #[DataProvider('skipStringCases')]
    public function testSkipString(string $text, int $start, int $expectedEnd): void
    {
        self::assertSame($expectedEnd, HclScanner::skipString($text, $start));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function skipStringCases(): iterable
    {
        yield 'plain string' => ['"abc" tail', 0, 5];

        yield 'string after a prefix' => ['x = "abc" tail', 4, 9];

        yield 'escaped quote' => ['"a\"b" tail', 0, 6];

        yield 'escaped backslash before the closing quote' => ['"a\\\\" tail', 0, 5];

        yield 'dollar template' => ['"a${b}c" tail', 0, 8];

        yield 'percent template' => ['"a%{if x}c%{endif}d" tail', 0, 20];

        yield 'template holding a string with a brace' => ['"${"}"}" tail', 0, 8];

        yield 'template holding nested braces' => ['"${ {a = 1}.a }" tail', 0, 16];

        yield 'doubled dollar is not a template' => ['"$${b}" tail', 0, 7];

        yield 'doubled percent is not a template' => ['"%%{b}" tail', 0, 7];

        yield 'doubled dollar after other text' => ['"ab$${c}d" tail', 0, 10];

        yield 'dollar without a brace' => ['"a$b" tail', 0, 5];

        yield 'percent without a brace' => ['"a%b" tail', 0, 5];

        yield 'brace without a dollar' => ['"a{b}" tail', 0, 6];

        yield 'second template after a first one' => ['"${a}${b}" tail', 0, 10];

        yield 'template whose preceding character is the same dollar' => ['"$${a}${b}" tail', 0, 11];
    }

    public function testSkipStringRejectsAnUnterminatedString(): void
    {
        $this->assertRejected('hcl: unterminated string literal', static fn (): mixed => HclScanner::skipString('"abc', 0));
    }

    public function testSkipStringRejectsANewline(): void
    {
        $this->assertRejected('hcl: newline in string literal', static fn (): mixed => HclScanner::skipString("\"a\nb\"", 0));
    }

    #[DataProvider('skipBracesCases')]
    public function testSkipBraces(string $text, int $start, int $expectedEnd): void
    {
        self::assertSame($expectedEnd, HclScanner::skipBraces($text, $start));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function skipBracesCases(): iterable
    {
        yield 'empty braces' => ['{} tail', 0, 2];

        yield 'one level' => ['{a} tail', 0, 3];

        yield 'nested braces' => ['{a{b}c} tail', 0, 7];

        yield 'braces inside a string' => ['{"}"} tail', 0, 5];

        yield 'braces after a prefix' => ['x{a}y', 1, 4];
    }

    public function testSkipBracesRejectsUnbalancedBraces(): void
    {
        $this->assertRejected('hcl: unbalanced braces', static fn (): mixed => HclScanner::skipBraces('{a{b}', 0));
    }

    #[DataProvider('expressionEndCases')]
    public function testExpressionEnd(string $text, int $start, int $expectedEnd): void
    {
        self::assertSame($expectedEnd, HclScanner::expressionEnd($text, $start));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function expressionEndCases(): iterable
    {
        yield 'ends at a newline' => ["1 + 2\nb = 3", 0, 5];

        yield 'ends at the end of the text' => ['1 + 2', 0, 5];

        yield 'ends at a hash comment' => ['1 + 2 # c', 0, 6];

        yield 'ends at a slash comment' => ['1 + 2 // c', 0, 6];

        yield 'a single slash is division' => ['4 / 2', 0, 5];

        yield 'ends at a closing bracket outside any bracket' => ['1}', 0, 1];

        yield 'brackets keep the expression going over newlines' => ["[1,\n2]\nb", 0, 6];

        yield 'parentheses keep the expression going' => ["(1 +\n2)\nb", 0, 7];

        yield 'braces keep the expression going' => ["{a = 1\nb = 2}\nc", 0, 13];

        yield 'comments inside brackets are skipped' => ["[1, # c\n2]\nb", 0, 10];

        yield 'slash comments inside brackets are skipped' => ["[1, // c\n2]\nb", 0, 11];

        yield 'block comment is skipped' => ["1 /* c\nd */ + 2\nb", 0, 15];

        yield 'block comment hides a newline' => ["a /* x\n */ b\nc", 0, 12];

        yield 'unterminated block comment runs to the end' => ["a /* x\nb", 0, 8];

        yield 'string hides a hash' => ['"a#b" + 1', 0, 9];

        yield 'string hides a bracket' => ['"a}b" + 1', 0, 9];

        yield 'heredoc is skipped as a whole' => ["<<EOT\nline # 1\nEOT\nnext", 0, 18];

        yield 'indented heredoc' => ["<<-EOT\n  a\n  EOT\nnext", 0, 16];

        yield 'heredoc after a prefix' => ["f(<<EOT\nx\nEOT\n)\nb", 2, 13];

        yield 'less than is not a heredoc' => ["a < b\nc", 0, 5];

        yield 'start inside the text' => ["x = 1\ny = 2", 4, 5];
    }

    public function testExpressionEndRejectsUnbalancedBrackets(): void
    {
        $this->assertRejected('hcl: unbalanced brackets in expression', static fn (): mixed => HclScanner::expressionEnd('[1, 2', 0));
    }

    public function testExpressionEndRejectsAnUnterminatedHeredoc(): void
    {
        $this->assertRejected('hcl: unterminated heredoc EOT', static fn (): mixed => HclScanner::expressionEnd("<<EOT\nabc\n", 0));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('splitTopCases')]
    public function testSplitTop(string $inner, bool $newlines, array $expected): void
    {
        self::assertSame($expected, HclScanner::splitTop($inner, $newlines));
    }

    /**
     * @return iterable<string, array{string, bool, list<string>}>
     */
    public static function splitTopCases(): iterable
    {
        yield 'commas' => ['a, b, c', false, ['a', 'b', 'c']];

        yield 'empty pieces are dropped and the list is re-indexed' => ['a, , b,, c,', false, ['a', 'b', 'c']];

        yield 'newlines split when asked' => ["a = 1\nb = 2", true, ['a = 1', 'b = 2']];

        yield 'newlines do not split otherwise' => ["a\nb", false, ["a\nb"]];

        yield 'nested brackets stay together' => ['[1, 2], {a = 1, b = 2}, (3, 4)', false, ['[1, 2]', '{a = 1, b = 2}', '(3, 4)']];

        yield 'strings stay together' => ['"a, b", "c"', false, ['"a, b"', '"c"']];

        yield 'string with a bracket' => ['"a]", b', false, ['"a]"', 'b']];

        yield 'nested newline does not split' => ["{a = 1\nb = 2}, c", true, ["{a = 1\nb = 2}", 'c']];

        yield 'empty input' => ['', false, []];

        yield 'only separators' => [",,\n", true, []];

        yield 'whitespace is trimmed' => ["  a  ,\t b\t", false, ['a', 'b']];
    }

    #[DataProvider('hasCommentCases')]
    public function testHasComment(string $text, bool $expected): void
    {
        self::assertSame($expected, HclScanner::hasComment($text));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function hasCommentCases(): iterable
    {
        yield 'no comment' => ['1 + 2', false];

        yield 'hash' => ['1 # c', true];

        yield 'slash slash' => ['1 // c', true];

        yield 'block comment' => ['1 /* c */', true];

        yield 'single slash' => ['4 / 2', false];

        yield 'slash at the end' => ['4 /', false];

        yield 'hash inside a string' => ['"a#b"', false];

        yield 'slashes inside a string' => ['"a//b"', false];

        yield 'comment after a string' => ['"x" # c', true];

        yield 'comment after two strings' => ['"x" "y" // c', true];

        yield 'string after a comment marker' => ['"x" /* "y" */', true];

        yield 'slash then a letter' => ['a/b', false];
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function assertRejected(string $expectedMessage, \Closure $call): void
    {
        try {
            $call();
        } catch (FormatException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }
}
