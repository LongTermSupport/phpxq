<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

use LTS\PhpXq\Limits\NestingLimit;
use LTS\PhpXq\Yq\Expression\Parser\StringLiteral;

/**
 * Byte scanner for the yq expression language.
 *
 * Token rules worth knowing:
 *
 * - Directly after a `.` (no whitespace between) a run of name characters is one Word token, whatever it
 *   looks like (`.a`, `.0`, `.a*`, `.if`, `.+@attr`). A name ends at whitespace or one of
 *   `. [ ] ( ) { } | , ; = : " ' ! ? < > / % & # \`. `-` and `*` stay inside a name.
 * - Elsewhere a Word is `[A-Za-z_~]` followed by `[A-Za-z0-9_]` (bytes above 0x7F count as letters) or `@`
 *   followed by word characters, so function names, keywords, `@format` encoders and the `~` null are all Words.
 * - A multiply operator takes yq's modifiers: `*`, optionally `=` (assign), then up to three of `+ ? d n c`
 *   provided no word character follows (otherwise the modifiers are not modifiers, `*dd` is `*` and `dd`).
 *   `=c` is assignment that clobbers custom tags, taken only when no word character follows the `c`.
 * - A double-quoted string's Token text is the decoded content; when it contains `\(` it is flagged `raw`, its
 *   text is empty and its parts hold the decoded literal text and the tokens of each interpolation. An interpolation is tokenized in the same pass, up to the `)` that balances its `\(`, so
 *   every byte of a nested interpolation is scanned once however deep it nests (up to
 *   {@see NestingLimit::MAX_DEPTH} levels). Single-quoted strings are verbatim.
 *
 * @internal
 */
final readonly class ExpressionLexer implements ExpressionLexerInterface
{
    private const string NAME_STOP = " \t\r\n.[](){}|,;=:\"'!?<>/%&#\\";

    private const string WORD_START = '/\G(?:@[A-Za-z0-9_]+|[A-Za-z_~\x80-\xff][A-Za-z0-9_\x80-\xff]*)/';

    private const string NUMBER = '/\G(?:0[xX][0-9a-fA-F]+|0[oO][0-7]+|[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/';

    private const string VARIABLE = '/\G\$([A-Za-z0-9_\x80-\xff]+)/';

    private const string WORD_CHAR = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    private const string UNTERMINATED = 'Bad expression, unterminated string';

    private const string MISSING_PAREN = 'Bad expression, could not find matching `)`';

    private const string TOO_DEEP = 'Bad expression, nested deeper than %d levels';

    public function tokenize(string $expression): array
    {
        return $this->scan($expression, 0, 0)[0];
    }

    /**
     * The tokens from $index to the end of the expression or, inside an interpolation, to the `)` closing it.
     *
     * @param int $nesting how many interpolations enclose $index
     *
     * @return array{non-empty-list<ExpressionToken>, int} the tokens, ending with an EndOfInput token, and the
     *                                                     byte index just after them
     *
     * @throws ExpressionSyntaxException
     */
    private function scan(string $expression, int $index, int $nesting): array
    {
        $tokens = [];
        $length = \strlen($expression);
        $start  = $index;
        $nameAt = -1;
        $parens = 0;

        while (true) {
            if ($index === $nameAt) {
                $nameLength = $this->nameLength($expression, $index);
                if ($nameLength > 0) {
                    $tokens[] = new ExpressionToken(ExpressionTokenKindEnum::Word, substr($expression, $index, $nameLength), $index);
                    $index   += $nameLength;

                    continue;
                }
            }

            $index += strspn($expression, " \t\r\n", $index);
            if ($index >= $length) {
                if ($nesting > 0) {
                    throw new ExpressionSyntaxException(self::MISSING_PAREN, $start);
                }

                break;
            }

            $char = $expression[$index];
            if ('#' === $char) {
                $newline = strpos($expression, "\n", $index);
                $index   = false === $newline ? $length : $newline + 1;

                continue;
            }

            if ('.' === $char) {
                if ('.' === substr($expression, $index + 1, 1)) {
                    $three    = '.' === substr($expression, $index + 2, 1);
                    $tokens[] = new ExpressionToken($three ? ExpressionTokenKindEnum::DotDotDot : ExpressionTokenKindEnum::DotDot, $three ? '...' : '..', $index);
                    $index   += $three ? 3 : 2;

                    continue;
                }

                $tokens[] = new ExpressionToken(ExpressionTokenKindEnum::Dot, '.', $index);
                ++$index;
                $nameAt = $index;

                continue;
            }

            if ('"' === $char) {
                [$tokens[], $index] = $this->doubleQuoted($expression, $index, $nesting);

                continue;
            }

            if ($nesting > 0 && ')' === $char) {
                if (0 === $parens) {
                    $tokens[] = new ExpressionToken(ExpressionTokenKindEnum::EndOfInput, '', $index);

                    return [$tokens, $index + 1];
                }

                --$parens;
            } elseif ('(' === $char) {
                ++$parens;
            }

            $token    = $this->scanToken($expression, $index, $char);
            $tokens[] = $token;
            $index    = $this->tokenEnd($token, $expression);
        }

        $tokens[] = new ExpressionToken(ExpressionTokenKindEnum::EndOfInput, '', $length);

        return [$tokens, $length];
    }

    /**
     * The source offset just after a token other than a double-quoted string.
     */
    private function tokenEnd(ExpressionToken $token, string $expression): int
    {
        if (ExpressionTokenKindEnum::String === $token->kind) {
            return (int)strpos($expression, "'", $token->offset + 1) + 1;
        }

        return $token->offset + (ExpressionTokenKindEnum::Variable === $token->kind ? 1 : 0) + \strlen($token->text);
    }

    private function scanToken(string $expression, int $index, string $char): ExpressionToken
    {
        $kind = match ($char) {
            '['     => ExpressionTokenKindEnum::LeftBracket,
            ']'     => ExpressionTokenKindEnum::RightBracket,
            '('     => ExpressionTokenKindEnum::LeftParen,
            ')'     => ExpressionTokenKindEnum::RightParen,
            '{'     => ExpressionTokenKindEnum::LeftBrace,
            '}'     => ExpressionTokenKindEnum::RightBrace,
            ';'     => ExpressionTokenKindEnum::Semicolon,
            ':'     => ExpressionTokenKindEnum::Colon,
            '?'     => ExpressionTokenKindEnum::Question,
            default => null,
        };
        if (null !== $kind) {
            return new ExpressionToken($kind, $char, $index);
        }

        if ("'" === $char) {
            $close = strpos($expression, "'", $index + 1);
            if (false === $close) {
                throw new ExpressionSyntaxException('Bad expression, unterminated string', $index);
            }

            return new ExpressionToken(ExpressionTokenKindEnum::String, substr($expression, $index + 1, $close - $index - 1), $index);
        }

        if ($char >= '0' && $char <= '9') {
            if (1 !== preg_match(self::NUMBER, $expression, $match, 0, $index)) {
                throw $this->unmatched($expression, $index);
            }

            return new ExpressionToken(ExpressionTokenKindEnum::Number, $match[0], $index);
        }

        if ('$' === $char) {
            if (1 !== preg_match(self::VARIABLE, $expression, $match, 0, $index)) {
                throw $this->unmatched($expression, $index);
            }

            return new ExpressionToken(ExpressionTokenKindEnum::Variable, $match[1], $index);
        }

        if (1 === preg_match(self::WORD_START, $expression, $match, 0, $index)) {
            return new ExpressionToken(ExpressionTokenKindEnum::Word, $match[0], $index);
        }

        $operator = $this->operator($expression, $index, $char);
        if (null === $operator) {
            throw $this->unmatched($expression, $index);
        }

        return new ExpressionToken(ExpressionTokenKindEnum::Operator, $operator, $index);
    }

    /**
     * The double-quoted string opening at $index, with the tokens of its interpolations.
     *
     * @return array{ExpressionToken, int} the token and the byte index just after the closing quote
     *
     * @throws ExpressionSyntaxException
     */
    private function doubleQuoted(string $expression, int $index, int $nesting): array
    {
        $length  = \strlen($expression);
        $parts   = [];
        $literal = $index + 1;
        $at      = $literal;
        while (true) {
            $at += strcspn($expression, '"\\', $at);
            if ($at >= $length) {
                throw new ExpressionSyntaxException(self::UNTERMINATED, $index);
            }

            if ('"' === $expression[$at]) {
                break;
            }

            // a backslash ending the text leaves the string unterminated, which the next pass reports
            if ($at + 1 >= $length || '(' !== $expression[$at + 1]) {
                $at += 2;

                continue;
            }

            if ($at > $literal) {
                $parts[] = StringLiteral::decode(substr($expression, $literal, $at - $literal));
            }

            if ($nesting >= NestingLimit::MAX_DEPTH) {
                throw new ExpressionSyntaxException(\sprintf(self::TOO_DEEP, NestingLimit::MAX_DEPTH), $at);
            }

            [$parts[], $at] = $this->scan($expression, $at + 2, $nesting + 1);
            $literal        = $at;
        }

        $tail = substr($expression, $literal, $at - $literal);
        if ($literal === $index + 1 && !str_contains($tail, '\(')) {
            return [new ExpressionToken(ExpressionTokenKindEnum::String, StringLiteral::decode($tail), $index), $at + 1];
        }

        if ('' !== $tail) {
            $parts[] = StringLiteral::decode($tail);
        }

        // the text stays empty: copying each nested body into its token would cost memory quadratic in the depth
        return [new ExpressionToken(ExpressionTokenKindEnum::String, '', $index, true, $parts), $at + 1];
    }

    private function operator(string $expression, int $index, string $char): ?string
    {
        $next = substr($expression, $index + 1, 1);

        return match ($char) {
            '|'     => '=' === $next ? '|=' : '|',
            ','     => ',',
            '='     => $this->assignOperator($expression, $index, $next),
            '!'     => '=' === $next ? '!=' : null,
            '<'     => '=' === $next ? '<=' : '<',
            '>'     => '=' === $next ? '>=' : '>',
            '+'     => '=' === $next ? '+=' : '+',
            '-'     => '=' === $next ? '-=' : '-',
            '/'     => match ($next) {
                '/'     => '//',
                '='     => '/=',
                default => '/',
            },
            '%'     => '=' === $next ? '%=' : '%',
            '*'     => $this->multiplyOperator($expression, $index),
            default => null,
        };
    }

    private function assignOperator(string $expression, int $index, string $next): string
    {
        if ('=' === $next) {
            return '==';
        }

        if ('c' === $next && !$this->isWordChar(substr($expression, $index + 2, 1))) {
            return '=c';
        }

        return '=';
    }

    private function multiplyOperator(string $expression, int $index): string
    {
        $end = $index + 1;
        if ('=' === substr($expression, $end, 1)) {
            ++$end;
        }

        $base      = $end;
        $end      += strspn($expression, '+?dnc', $end, 3);
        $modifiers = substr($expression, $base, $end - $base);
        $letters   = \strlen($modifiers) - substr_count($modifiers, '+') - substr_count($modifiers, '?');
        if ($end > $base && ($letters > 1 || $this->isWordChar(substr($expression, $end, 1)))) {
            $end = $base;
        }

        return substr($expression, $index, $end - $index);
    }

    private function isWordChar(string $char): bool
    {
        return '' !== $char && (str_contains(self::WORD_CHAR, $char) || $char >= "\x80");
    }

    private function nameLength(string $expression, int $index): int
    {
        if ('+' === substr($expression, $index, 1)) {
            return 1 + strcspn($expression, self::NAME_STOP . '+', $index + 1);
        }

        return strcspn($expression, self::NAME_STOP, $index);
    }

    private function unmatched(string $expression, int $offset): ExpressionSyntaxException
    {
        $before = substr($expression, 0, $offset);
        $line   = 1 + substr_count($before, "\n");
        $lastNl = strrpos($before, "\n");
        $column = $offset - (false === $lastNl ? 0 : $lastNl + 1) + 1;

        return new ExpressionSyntaxException(
            \sprintf('Parsing expression: Lexer error: could not match text starting at %d:%d failing at %d:%d.', $line, $column, $line, $column + 1),
            $offset,
        );
    }
}
