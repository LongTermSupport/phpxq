<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Expression;

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
 * - A double-quoted string's Token text is the decoded content; when it contains `\(` it is flagged `raw` and
 *   the text is the undecoded body so the parser can split out the interpolations. Single-quoted strings are
 *   verbatim.
 */
final class ExpressionLexer implements ExpressionLexerInterface
{
    private const string NAME_STOP = " \t\r\n.[](){}|,;=:\"'!?<>/%&#\\";

    private const string WORD_START = '/\G(?:@[A-Za-z0-9_]+|[A-Za-z_~\x80-\xff][A-Za-z0-9_\x80-\xff]*)/';

    private const string NUMBER = '/\G(?:0[xX][0-9a-fA-F]+|0[oO][0-7]+|[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/';

    private const string VARIABLE = '/\G\$([A-Za-z0-9_\x80-\xff]+)/';

    private const string WORD_CHAR = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    public function tokenize(string $expression): array
    {
        $tokens = [];
        $length = \strlen($expression);
        $index  = 0;
        $nameAt = -1;

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
                break;
            }

            $char = $expression[$index];
            if ('#' === $char) {
                $newline = strpos($expression, "\n", $index);
                $index   = false === $newline ? $length : $newline + 1;

                continue;
            }

            if ('.' === $char) {
                if ('.' === ($expression[$index + 1] ?? '')) {
                    $three    = '.' === ($expression[$index + 2] ?? '');
                    $tokens[] = new ExpressionToken($three ? ExpressionTokenKindEnum::DotDotDot : ExpressionTokenKindEnum::DotDot, $three ? '...' : '..', $index);
                    $index   += $three ? 3 : 2;

                    continue;
                }

                $tokens[] = new ExpressionToken(ExpressionTokenKindEnum::Dot, '.', $index);
                ++$index;
                $nameAt = $index;

                continue;
            }

            $token    = $this->scanToken($expression, $index, $char);
            $tokens[] = $token;
            $index    = $this->tokenEnd($token, $expression);
        }

        $tokens[] = new ExpressionToken(ExpressionTokenKindEnum::EndOfInput, '', $length);

        return $tokens;
    }

    /**
     * The source offset just after a token; a string's text is decoded so its end is recomputed.
     */
    private function tokenEnd(ExpressionToken $token, string $expression): int
    {
        if (ExpressionTokenKindEnum::String === $token->kind) {
            $close = '"' === $expression[$token->offset]
                ? StringLiteral::scanDouble($expression, $token->offset + 1)
                : (int)strpos($expression, "'", $token->offset + 1);

            return $close + 1;
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

        if ('"' === $char) {
            return $this->doubleQuoted($expression, $index);
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

    private function doubleQuoted(string $expression, int $index): ExpressionToken
    {
        $close = StringLiteral::scanDouble($expression, $index + 1);
        $body  = substr($expression, $index + 1, $close - $index - 1);
        if (str_contains($body, '\(')) {
            return new ExpressionToken(ExpressionTokenKindEnum::String, $body, $index, true);
        }

        return new ExpressionToken(ExpressionTokenKindEnum::String, StringLiteral::decode($body), $index);
    }

    private function operator(string $expression, int $index, string $char): ?string
    {
        $next = $expression[$index + 1] ?? '';

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

        if ('c' === $next && !$this->isWordChar($expression[$index + 2] ?? '')) {
            return '=c';
        }

        return '=';
    }

    private function multiplyOperator(string $expression, int $index): string
    {
        $end = $index + 1;
        if ('=' === ($expression[$end] ?? '')) {
            ++$end;
        }

        $base      = $end;
        $end      += strspn($expression, '+?dnc', $end, 3);
        $modifiers = substr($expression, $base, $end - $base);
        $letters   = \strlen($modifiers) - substr_count($modifiers, '+') - substr_count($modifiers, '?');
        if ($end > $base && ($letters > 1 || $this->isWordChar($expression[$end] ?? ''))) {
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
        if ('+' === ($expression[$index] ?? '')) {
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
