<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression;

use Generator;
use LTS\PhpXq\Yq\Expression\ExpressionLexer;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Expression\ExpressionToken;
use LTS\PhpXq\Yq\Expression\ExpressionTokenKindEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Token kinds, text and byte offsets of the expression lexer. A table row is a source, an arrow and the tokens
 * as `Kind:text@offset` separated by spaces.
 *
 * @internal
 */
#[CoversClass(ExpressionLexer::class)]
final class ExpressionLexerBoundaryTest extends TestCase
{
    private const string ARROW = '➜';

    private const string DOT_A = '.a';

    private const string FIRST_COLUMN = '1:1 failing at 1:2';

    #[DataProvider('tokenProvider')]
    public function testTokens(string $source, string $expected): void
    {
        self::assertSame($expected, $this->lex($source));
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function tokenProvider(): Generator
    {
        yield from self::rows(<<<'TABLE'
            .a-b➜Dot:.@0 Word:a-b@1 EOF:@4
            .a*➜Dot:.@0 Word:a*@1 EOF:@3
            .+@c➜Dot:.@0 Word:+@c@1 EOF:@4
            .+@c+d➜Dot:.@0 Word:+@c@1 Op:+@4 Word:d@5 EOF:@6
            .ab c➜Dot:.@0 Word:ab@1 Word:c@4 EOF:@5
            .a.b➜Dot:.@0 Word:a@1 Dot:.@2 Word:b@3 EOF:@4
            .a[0]➜Dot:.@0 Word:a@1 LB:[@2 Num:0@3 RB:]@4 EOF:@5
            .a]➜Dot:.@0 Word:a@1 RB:]@2 EOF:@3
            .a(➜Dot:.@0 Word:a@1 LP:(@2 EOF:@3
            .a)➜Dot:.@0 Word:a@1 RP:)@2 EOF:@3
            .a{➜Dot:.@0 Word:a@1 LC:{@2 EOF:@3
            .a}➜Dot:.@0 Word:a@1 RC:}@2 EOF:@3
            .a|b➜Dot:.@0 Word:a@1 Op:|@2 Word:b@3 EOF:@4
            .a,b➜Dot:.@0 Word:a@1 Op:,@2 Word:b@3 EOF:@4
            .a;b➜Dot:.@0 Word:a@1 Semi:;@2 Word:b@3 EOF:@4
            .a=b➜Dot:.@0 Word:a@1 Op:=@2 Word:b@3 EOF:@4
            .a:b➜Dot:.@0 Word:a@1 Colon::@2 Word:b@3 EOF:@4
            .a"x"➜Dot:.@0 Word:a@1 Str:x@2 EOF:@5
            .a'x'➜Dot:.@0 Word:a@1 Str:x@2 EOF:@5
            .a!=b➜Dot:.@0 Word:a@1 Op:!=@2 Word:b@4 EOF:@5
            .a?➜Dot:.@0 Word:a@1 Q:?@2 EOF:@3
            .a<b➜Dot:.@0 Word:a@1 Op:<@2 Word:b@3 EOF:@4
            .a>b➜Dot:.@0 Word:a@1 Op:>@2 Word:b@3 EOF:@4
            .a/b➜Dot:.@0 Word:a@1 Op:/@2 Word:b@3 EOF:@4
            .a%b➜Dot:.@0 Word:a@1 Op:%@2 Word:b@3 EOF:@4
            .a#c➜Dot:.@0 Word:a@1 EOF:@4
            ..➜DD:..@0 EOF:@2
            ...➜DDD:...@0 EOF:@3
            ....➜DDD:...@0 Dot:.@3 EOF:@4
            . .➜Dot:.@0 Dot:.@2 EOF:@3
            .. .➜DD:..@0 Dot:.@3 EOF:@4
            .[➜Dot:.@0 LB:[@1 EOF:@2
            .1➜Dot:.@0 Word:1@1 EOF:@2
            . 1➜Dot:.@0 Num:1@2 EOF:@3
            .a.➜Dot:.@0 Word:a@1 Dot:.@2 EOF:@3
            ..a➜DD:..@0 Word:a@2 EOF:@3
            .."a"➜DD:..@0 Str:a@2 EOF:@5
            .➜Dot:.@0 EOF:@1
            .)➜Dot:.@0 RP:)@1 EOF:@2
            . )➜Dot:.@0 RP:)@2 EOF:@3
            a.b➜Word:a@0 Dot:.@1 Word:b@2 EOF:@3
            .é➜Dot:.@0 Word:é@1 EOF:@3
            .0x1F➜Dot:.@0 Word:0x1F@1 EOF:@5
            .a1➜Dot:.@0 Word:a1@1 EOF:@3
            # x➜EOF:@3
            .a # n➜Dot:.@0 Word:a@1 EOF:@6
            0x1F➜Num:0x1F@0 EOF:@4
            0X1f➜Num:0X1f@0 EOF:@4
            0o17➜Num:0o17@0 EOF:@4
            0O17➜Num:0O17@0 EOF:@4
            1.5➜Num:1.5@0 EOF:@3
            1e3➜Num:1e3@0 EOF:@3
            1E+3➜Num:1E+3@0 EOF:@4
            1e-3➜Num:1e-3@0 EOF:@4
            1.5e2➜Num:1.5e2@0 EOF:@5
            12➜Num:12@0 EOF:@2
            09➜Num:09@0 EOF:@2
            1.➜Num:1@0 Dot:.@1 EOF:@2
            0x➜Num:0@0 Word:x@1 EOF:@2
            0o8➜Num:0@0 Word:o8@1 EOF:@3
            1e➜Num:1@0 Word:e@1 EOF:@2
            1.e3➜Num:1@0 Dot:.@1 Word:e3@2 EOF:@4
            1 2➜Num:1@0 Num:2@2 EOF:@3
            $a➜Var:a@0 EOF:@2
            $a1_➜Var:a1_@0 EOF:@4
            $é➜Var:é@0 EOF:@3
            $1➜Var:1@0 EOF:@2
            $a.b➜Var:a@0 Dot:.@2 Word:b@3 EOF:@4
            $a $b➜Var:a@0 Var:b@3 EOF:@5
            abc_1➜Word:abc_1@0 EOF:@5
            _x➜Word:_x@0 EOF:@2
            ~➜Word:~@0 EOF:@1
            ~a➜Word:~a@0 EOF:@2
            @b64➜Word:@b64@0 EOF:@4
            a-b➜Word:a@0 Op:-@1 Word:b@2 EOF:@3
            é1➜Word:é1@0 EOF:@3
            a b➜Word:a@0 Word:b@2 EOF:@3
            |➜Op:|@0 EOF:@1
            |=➜Op:|=@0 EOF:@2
            ,➜Op:,@0 EOF:@1
            ==➜Op:==@0 EOF:@2
            =➜Op:=@0 EOF:@1
            !=➜Op:!=@0 EOF:@2
            <➜Op:<@0 EOF:@1
            <=➜Op:<=@0 EOF:@2
            >➜Op:>@0 EOF:@1
            >=➜Op:>=@0 EOF:@2
            +=➜Op:+=@0 EOF:@2
            +➜Op:+@0 EOF:@1
            -=➜Op:-=@0 EOF:@2
            -➜Op:-@0 EOF:@1
            /=➜Op:/=@0 EOF:@2
            //➜Op://@0 EOF:@2
            /➜Op:/@0 EOF:@1
            %=➜Op:%=@0 EOF:@2
            %➜Op:%@0 EOF:@1
            a|b➜Word:a@0 Op:|@1 Word:b@2 EOF:@3
            a||b➜Word:a@0 Op:|@1 Op:|@2 Word:b@3 EOF:@4
            =c➜Op:=c@0 EOF:@2
            =c1➜Op:=@0 Word:c1@1 EOF:@3
            =c_➜Op:=@0 Word:c_@1 EOF:@3
            =cA➜Op:=@0 Word:cA@1 EOF:@3
            =c9➜Op:=@0 Word:c9@1 EOF:@3
            =cé➜Op:=@0 Word:cé@1 EOF:@4
            =c.b➜Op:=c@0 Dot:.@2 Word:b@3 EOF:@4
            =c)➜Op:=c@0 RP:)@2 EOF:@3
            ==c➜Op:==@0 Word:c@2 EOF:@3
            =ca➜Op:=@0 Word:ca@1 EOF:@3
            = c➜Op:=@0 Word:c@2 EOF:@3
            *➜Op:*@0 EOF:@1
            *=➜Op:*=@0 EOF:@2
            *+➜Op:*+@0 EOF:@2
            *?➜Op:*?@0 EOF:@2
            *d➜Op:*d@0 EOF:@2
            *n➜Op:*n@0 EOF:@2
            *c➜Op:*c@0 EOF:@2
            *=c➜Op:*=c@0 EOF:@3
            *=+➜Op:*=+@0 EOF:@3
            *+?➜Op:*+?@0 EOF:@3
            *?+➜Op:*?+@0 EOF:@3
            *++➜Op:*++@0 EOF:@3
            *+++➜Op:*+++@0 EOF:@4
            *++++➜Op:*+++@0 Op:+@4 EOF:@5
            *+?d➜Op:*+?d@0 EOF:@4
            *d?➜Op:*d?@0 EOF:@3
            *d+➜Op:*d+@0 EOF:@3
            *+?dd➜Op:*@0 Op:+@1 Q:?@2 Word:dd@3 EOF:@5
            *dn➜Op:*@0 Word:dn@1 EOF:@3
            *dc➜Op:*@0 Word:dc@1 EOF:@3
            *a➜Op:*@0 Word:a@1 EOF:@2
            *d_➜Op:*@0 Word:d_@1 EOF:@3
            *dA➜Op:*@0 Word:dA@1 EOF:@3
            *d7➜Op:*@0 Word:d7@1 EOF:@3
            *dé➜Op:*@0 Word:dé@1 EOF:@4
            *d.➜Op:*d@0 Dot:.@2 EOF:@3
            *c)➜Op:*c@0 RP:)@2 EOF:@3
            *=cc➜Op:*=@0 Word:cc@2 EOF:@4
            * =➜Op:*@0 Op:=@2 EOF:@3
            *=d➜Op:*=d@0 EOF:@3
            *?d➜Op:*?d@0 EOF:@3
            "a"➜Str:a@0 EOF:@3
            ""➜Str:@0 EOF:@2
            ''➜Str:@0 EOF:@2
            "a\"b"➜Str:a"b@0 EOF:@6
            "a\\"➜Str:a\@0 EOF:@5
            'a"b'➜Str:a"b@0 EOF:@5
            "a" "b"➜Str:a@0 Str:b@4 EOF:@7
            'a' 'b'➜Str:a@0 Str:b@4 EOF:@7
            'a\'➜Str:a\@0 EOF:@4
            "x\(1)y" 2➜RawStr:@0 Num:2@9 EOF:@10
            "a\\(b"➜RawStr:@0 EOF:@7
            "\("x")" 1➜RawStr:@0 Num:1@9 EOF:@10
            "é"➜Str:é@0 EOF:@4
            { }➜LC:{@0 RC:}@2 EOF:@3
            [ ]➜LB:[@0 RB:]@2 EOF:@3
            ( )➜LP:(@0 RP:)@2 EOF:@3
            ;➜Semi:;@0 EOF:@1
            :➜Colon::@0 EOF:@1
            ?➜Q:?@0 EOF:@1
            ??➜Q:?@0 Q:?@1 EOF:@2
            TABLE);
    }

    #[DataProvider('whitespaceProvider')]
    public function testNamesAndWhitespace(string $space): void
    {
        self::assertSame('Dot:.@0 Word:ab@1 Word:c@4 EOF:@5', $this->lex('.ab' . $space . 'c'));
        self::assertSame('Dot:.@3 Word:a@4 EOF:@5', $this->lex($space . $space . $space . self::DOT_A));
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function whitespaceProvider(): Generator
    {
        foreach ([' ', "\t", "\r", "\n"] as $space) {
            yield \sprintf('name ends at %s', json_encode($space, \JSON_THROW_ON_ERROR)) => [$space];
        }
    }

    public function testCommentRunsToTheNewline(): void
    {
        self::assertSame('Dot:.@0 Word:a@1 Op:|@7 Dot:.@9 Word:b@10 EOF:@11', $this->lex(".a # x\n| .b"));
        self::assertSame('Dot:.@5 Word:a@6 EOF:@7', $this->lex("# c\n\n.a"));
        self::assertSame('EOF:@5', $this->lex('#abcd'));
        self::assertSame('EOF:@1', $this->lex('#'));
    }

    #[DataProvider('errorProvider')]
    public function testErrorsCarryOffsetsAndPositions(string $source, string $message, int $offset, ?string $position): void
    {
        try {
            new ExpressionLexer()->tokenize($source);
            self::fail('a lexer error was expected');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertSame($offset, $expressionSyntaxException->offset);
            if (null === $position) {
                self::assertSame($message, $expressionSyntaxException->getMessage());
            } else {
                self::assertSame('Parsing expression: Lexer error: could not match text starting at ' . $position . '.', $expressionSyntaxException->getMessage());
            }
        }
    }

    /**
     * @return Generator<string, array{string, string, int, ?string}>
     */
    public static function errorProvider(): Generator
    {
        $unterminated = 'Bad expression, unterminated string';

        yield 'unterminated double'       => ['"abc', $unterminated, 0, null];
        yield 'unterminated single'       => ["'abc", $unterminated, 0, null];
        yield 'unterminated after tokens' => [".a 'x", $unterminated, 3, null];
        yield 'unterminated double later' => ['.a | "x', $unterminated, 5, null];
        yield 'lone dollar'               => ['$', '', 0, self::FIRST_COLUMN];
        yield 'dollar then space'         => ['$ a', '', 0, self::FIRST_COLUMN];
        yield 'dollar later'              => ['.a $', '', 3, '1:4 failing at 1:5'];
        yield 'lone bang'                 => ['.a !', '', 3, '1:4 failing at 1:5'];
        yield 'bang then word'            => ['! a', '', 0, self::FIRST_COLUMN];
        yield 'ampersand in name'         => ['.a&b', '', 2, '1:3 failing at 1:4'];
        yield 'backslash'                 => ['\\', '', 0, self::FIRST_COLUMN];
        yield 'backtick'                  => ['`', '', 0, self::FIRST_COLUMN];
        yield 'at sign'                   => ['@ ', '', 0, self::FIRST_COLUMN];
        yield 'third line'                => ["a\n\n  &", '', 5, '3:3 failing at 3:4'];
        yield 'second line first column'  => [".a\n&", '', 3, '2:1 failing at 2:2'];
        yield 'second line'               => ["a\n  `", '', 4, '2:3 failing at 2:4'];
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    private static function rows(string $table): Generator
    {
        foreach (explode("\n", trim($table)) as $line) {
            [$source, $tokens] = explode(self::ARROW, $line);

            yield $line => [$source, $tokens];
        }
    }

    private function render(ExpressionToken $token): string
    {
        $kind = match ($token->kind) {
            ExpressionTokenKindEnum::Number       => 'Num',
            ExpressionTokenKindEnum::String       => $token->raw ? 'RawStr' : 'Str',
            ExpressionTokenKindEnum::Word         => 'Word',
            ExpressionTokenKindEnum::Variable     => 'Var',
            ExpressionTokenKindEnum::Operator     => 'Op',
            ExpressionTokenKindEnum::Dot          => 'Dot',
            ExpressionTokenKindEnum::DotDot       => 'DD',
            ExpressionTokenKindEnum::DotDotDot    => 'DDD',
            ExpressionTokenKindEnum::LeftBracket  => 'LB',
            ExpressionTokenKindEnum::RightBracket => 'RB',
            ExpressionTokenKindEnum::LeftParen    => 'LP',
            ExpressionTokenKindEnum::RightParen   => 'RP',
            ExpressionTokenKindEnum::LeftBrace    => 'LC',
            ExpressionTokenKindEnum::RightBrace   => 'RC',
            ExpressionTokenKindEnum::Semicolon    => 'Semi',
            ExpressionTokenKindEnum::Colon        => 'Colon',
            ExpressionTokenKindEnum::Question     => 'Q',
            ExpressionTokenKindEnum::EndOfInput   => 'EOF',
        };

        return \sprintf('%s:%s@%d', $kind, $token->text, $token->offset);
    }

    private function lex(string $source): string
    {
        return implode(' ', array_map($this->render(...), new ExpressionLexer()->tokenize($source)));
    }
}
