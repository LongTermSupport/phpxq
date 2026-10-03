<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Parser;

use LTS\PhpXq\Jq\Runtime\JqCompileException;

/**
 * Single-pass byte scanner for jq 1.8 source text.
 *
 * Columns are 1-based byte offsets within the line, as jq reports them. A stack of parenthesis depths
 * tracks string interpolation: the `)` that closes a `\(` becomes InterpEnd and scanning resumes inside
 * the enclosing string literal.
 *
 * @api
 */
final class Lexer implements LexerInterface
{
    private const array KEYWORDS = [
        'def'     => TokenTypeEnum::KwDef,
        'if'      => TokenTypeEnum::KwIf,
        'then'    => TokenTypeEnum::KwThen,
        'elif'    => TokenTypeEnum::KwElif,
        'else'    => TokenTypeEnum::KwElse,
        'end'     => TokenTypeEnum::KwEnd,
        'as'      => TokenTypeEnum::KwAs,
        'reduce'  => TokenTypeEnum::KwReduce,
        'foreach' => TokenTypeEnum::KwForeach,
        'try'     => TokenTypeEnum::KwTry,
        'catch'   => TokenTypeEnum::KwCatch,
        'label'   => TokenTypeEnum::KwLabel,
        'import'  => TokenTypeEnum::KwImport,
        'include' => TokenTypeEnum::KwInclude,
        'module'  => TokenTypeEnum::KwModule,
        'and'     => TokenTypeEnum::KwAnd,
        'or'      => TokenTypeEnum::KwOr,
    ];

    private const string IDENT_START = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_';

    private const string IDENT_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789';

    private const string DIGITS = '0123456789';

    private const string HEX = '0123456789abcdefABCDEF';

    private const array SIMPLE_ESCAPES = [
        '"'  => '"',
        '\\' => '\\',
        '/'  => '/',
        'b'  => "\x08",
        'f'  => "\x0c",
        'n'  => "\n",
        'r'  => "\r",
        't'  => "\t",
    ];

    private string $src = '';

    private int $len = 0;

    private int $pos = 0;

    private int $line = 1;

    private int $lineStart = 0;

    /** @var list<Token> */
    private array $tokens = [];

    public function tokenize(string $source): array
    {
        $this->src       = $source;
        $this->len       = \strlen($source);
        $this->pos       = 0;
        $this->line      = 1;
        $this->lineStart = 0;
        $this->tokens    = [];

        try {
            $eof    = $this->scan();
            $tokens = $this->tokens;
        } finally {
            $this->src    = '';
            $this->tokens = [];
        }

        $tokens[] = $eof;

        return $tokens;
    }

    private function scan(): Token
    {
        $src = $this->src;
        $len = $this->len;
        /** @var list<int> $saved parenthesis depth of each enclosing interpolation */
        $saved = [];
        $depth = 0;

        while (true) {
            $pos = $this->skipTrivia();
            if ($pos >= $len) {
                break;
            }

            $c      = $src[$pos];
            $column = $pos - $this->lineStart + 1;
            $line   = $this->line;
            $next   = $pos + 1 < $len ? $src[$pos + 1] : '';

            switch ($c) {
                case '"':
                    $this->tokens[] = new Token(TokenTypeEnum::StringStart, '"', $line, $column);
                    $this->pos      = $pos + 1;
                    if ($this->scanString()) {
                        $saved[] = $depth;
                        $depth   = 0;
                    }

                    break;

                case '.':
                    if ('.' === $next) {
                        $this->emit(TokenTypeEnum::DotDot, '..', $line, $column);
                    } elseif ('' !== $next && str_contains(self::DIGITS, $next)) {
                        $this->scanNumber($pos, $line, $column);
                    } elseif ('' !== $next && str_contains(self::IDENT_START, $next)) {
                        $end            = $pos + 1 + strspn($src, self::IDENT_CHARS, $pos + 1);
                        $this->tokens[] = new Token(TokenTypeEnum::Field, substr($src, $pos + 1, $end - $pos - 1), $line, $column);
                        $this->pos      = $end;
                    } else {
                        $this->emit(TokenTypeEnum::Dot, '.', $line, $column);
                    }

                    break;

                case '(':
                    ++$depth;
                    $this->emit(TokenTypeEnum::LParen, '(', $line, $column);

                    break;

                case ')':
                    if (0 === $depth && [] !== $saved) {
                        $depth          = array_pop($saved);
                        $this->tokens[] = new Token(TokenTypeEnum::InterpEnd, ')', $line, $column);
                        $this->pos      = $pos + 1;
                        if ($this->scanString()) {
                            $saved[] = $depth;
                            $depth   = 0;
                        }
                    } else {
                        if ($depth > 0) {
                            --$depth;
                        }

                        $this->emit(TokenTypeEnum::RParen, ')', $line, $column);
                    }

                    break;

                case '|':
                    if ('=' === $next) {
                        $this->emit(TokenTypeEnum::UpdateAssign, '|=', $line, $column);
                    } else {
                        $this->emit(TokenTypeEnum::Pipe, '|', $line, $column);
                    }

                    break;

                case '+':
                    $this->operatorWithEquals(TokenTypeEnum::Plus, TokenTypeEnum::PlusAssign, '+', $next, $line, $column);

                    break;

                case '-':
                    $this->operatorWithEquals(TokenTypeEnum::Minus, TokenTypeEnum::MinusAssign, '-', $next, $line, $column);

                    break;

                case '*':
                    $this->operatorWithEquals(TokenTypeEnum::Star, TokenTypeEnum::StarAssign, '*', $next, $line, $column);

                    break;

                case '%':
                    $this->operatorWithEquals(TokenTypeEnum::Percent, TokenTypeEnum::PercentAssign, '%', $next, $line, $column);

                    break;

                case '/':
                    if ('/' === $next) {
                        if ($pos + 2 < $len && '=' === $src[$pos + 2]) {
                            $this->emit(TokenTypeEnum::AltAssign, '//=', $line, $column);
                        } else {
                            $this->emit(TokenTypeEnum::Alt, '//', $line, $column);
                        }
                    } else {
                        $this->operatorWithEquals(TokenTypeEnum::Slash, TokenTypeEnum::SlashAssign, '/', $next, $line, $column);
                    }

                    break;

                case '=':
                    $this->operatorWithEquals(TokenTypeEnum::Assign, TokenTypeEnum::Eq, '=', $next, $line, $column);

                    break;

                case '!':
                    if ('=' !== $next) {
                        $this->invalidCharacter($line, $column);
                    }

                    $this->emit(TokenTypeEnum::Neq, '!=', $line, $column);

                    break;

                case '<':
                    $this->operatorWithEquals(TokenTypeEnum::Lt, TokenTypeEnum::Le, '<', $next, $line, $column);

                    break;

                case '>':
                    $this->operatorWithEquals(TokenTypeEnum::Gt, TokenTypeEnum::Ge, '>', $next, $line, $column);

                    break;

                case '?':
                    if ('/' === $next && $pos + 2 < $len && '/' === $src[$pos + 2]) {
                        $this->emit(TokenTypeEnum::DestructAlt, '?//', $line, $column);
                    } else {
                        $this->emit(TokenTypeEnum::Question, '?', $line, $column);
                    }

                    break;

                case ',':
                    $this->emit(TokenTypeEnum::Comma, ',', $line, $column);

                    break;

                case ':':
                    $this->emit(TokenTypeEnum::Colon, ':', $line, $column);

                    break;

                case ';':
                    $this->emit(TokenTypeEnum::Semicolon, ';', $line, $column);

                    break;

                case '[':
                    $this->emit(TokenTypeEnum::LBracket, '[', $line, $column);

                    break;

                case ']':
                    $this->emit(TokenTypeEnum::RBracket, ']', $line, $column);

                    break;

                case '{':
                    $this->emit(TokenTypeEnum::LBrace, '{', $line, $column);

                    break;

                case '}':
                    $this->emit(TokenTypeEnum::RBrace, '}', $line, $column);

                    break;

                case '$':
                    if ('' === $next || !str_contains(self::IDENT_START, $next)) {
                        $this->invalidCharacter($line, $column);
                    }

                    $end            = $this->identEnd($pos + 1);
                    $this->tokens[] = new Token(TokenTypeEnum::Variable, substr($src, $pos + 1, $end - $pos - 1), $line, $column);
                    $this->pos      = $end;

                    break;

                case '@':
                    $end = $pos + 1 + strspn($src, self::IDENT_CHARS, $pos + 1);
                    if ($end === $pos + 1) {
                        $this->invalidCharacter($line, $column);
                    }

                    $this->tokens[] = new Token(TokenTypeEnum::Format, substr($src, $pos + 1, $end - $pos - 1), $line, $column);
                    $this->pos      = $end;

                    break;

                default:
                    if (str_contains(self::DIGITS, $c)) {
                        $this->scanNumber($pos, $line, $column);
                    } elseif (str_contains(self::IDENT_START, $c)) {
                        $end            = $this->identEnd($pos);
                        $text           = substr($src, $pos, $end - $pos);
                        $this->tokens[] = new Token(self::KEYWORDS[$text] ?? TokenTypeEnum::Ident, $text, $line, $column);
                        $this->pos      = $end;
                    } else {
                        $this->invalidCharacter($line, $column);
                    }
            }
        }

        if ([] !== $saved) {
            $this->pos = $len;
            $this->unexpectedEnd();
        }

        // like jq, the end of input sits on the final line break rather than after it
        if ($len > 0 && "\n" === $src[$len - 1]) {
            $previous = $len >= 2 ? strrpos($src, "\n", -2) : false;

            return new Token(TokenTypeEnum::Eof, '', $this->line - 1, $len - (false === $previous ? 0 : $previous + 1));
        }

        return new Token(TokenTypeEnum::Eof, '', $this->line, $len - $this->lineStart + 1);
    }

    /**
     * Skip whitespace and comments, keeping line accounting; returns the offset of the next token.
     */
    private function skipTrivia(): int
    {
        $src = $this->src;
        $len = $this->len;
        $pos = $this->pos;

        while ($pos < $len) {
            $c = $src[$pos];
            if (' ' === $c || "\t" === $c || "\r" === $c) {
                ++$pos;
            } elseif ("\n" === $c) {
                ++$this->line;
                $this->lineStart = ++$pos;
            } elseif ('#' === $c) {
                $pos = $this->skipComment($pos);
            } else {
                break;
            }
        }

        return $this->pos = $pos;
    }

    /**
     * Skip a `#` comment starting at $pos; returns the offset of the terminating line break (or end of
     * input). An odd number of backslashes directly before a line break continues the comment.
     */
    private function skipComment(int $pos): int
    {
        $src = $this->src;
        $len = $this->len;
        ++$pos;
        while (true) {
            $pos += strcspn($src, "\n\\", $pos);
            if ($pos >= $len || '\\' !== $src[$pos]) {
                return $pos;
            }

            $run   = strspn($src, '\\', $pos);
            $after = $pos + $run;
            if (1 === $run % 2 && $after < $len) {
                $skip = 0;
                if ("\n" === $src[$after]) {
                    $skip = 1;
                } elseif ("\r" === $src[$after] && $after + 1 < $len && "\n" === $src[$after + 1]) {
                    $skip = 2;
                }

                if ($skip > 0) {
                    $pos = $after + $skip;
                    ++$this->line;
                    $this->lineStart = $pos;

                    continue;
                }
            }

            $pos = $after;
        }
    }

    private function emit(TokenTypeEnum $type, string $text, int $line, int $column): void
    {
        $this->tokens[] = new Token($type, $text, $line, $column);
        $this->pos += \strlen($text);
    }

    private function operatorWithEquals(TokenTypeEnum $plain, TokenTypeEnum $withEquals, string $text, string $next, int $line, int $column): void
    {
        if ('=' === $next) {
            $this->emit($withEquals, $text . '=', $line, $column);
        } else {
            $this->emit($plain, $text, $line, $column);
        }
    }

    /**
     * End offset of an identifier (with `::` namespace parts) starting at $start, which must be an
     * identifier start byte.
     */
    private function identEnd(int $start): int
    {
        $src = $this->src;
        $end = $start + strspn($src, self::IDENT_CHARS, $start);
        while ($end + 2 < $this->len && ':' === $src[$end] && ':' === $src[$end + 1] && str_contains(self::IDENT_START, $src[$end + 2])) {
            $end += 2;
            $end += strspn($src, self::IDENT_CHARS, $end);
        }

        return $end;
    }

    private function scanNumber(int $pos, int $line, int $column): void
    {
        $src = $this->src;
        $len = $this->len;
        $end = $pos + strspn($src, self::DIGITS, $pos);
        if ($end < $len && '.' === $src[$end]) {
            $end += 1 + strspn($src, self::DIGITS, $end + 1);
        }

        if ($end < $len && ('e' === $src[$end] || 'E' === $src[$end])) {
            $e = $end + 1;
            if ($e < $len && ('+' === $src[$e] || '-' === $src[$e])) {
                ++$e;
            }

            $digits = strspn($src, self::DIGITS, $e);
            if ($digits > 0) {
                $end = $e + $digits;
            }
        }

        $this->tokens[] = new Token(TokenTypeEnum::Number, substr($src, $pos, $end - $pos), $line, $column);
        $this->pos      = $end;
    }

    /**
     * Scan string body from $this->pos up to the closing quote (returns false) or an interpolation
     * opener (returns true; InterpStart was emitted).
     */
    private function scanString(): bool
    {
        $src        = $this->src;
        $len        = $this->len;
        $pos        = $this->pos;
        $fragment   = '';
        $fragLine   = $this->line;
        $fragColumn = $pos - $this->lineStart + 1;

        while (true) {
            $n = strcspn($src, '"\\', $pos);
            if ($n > 0) {
                $chunk = substr($src, $pos, $n);
                $fragment .= $chunk;
                $this->countNewlines($chunk, $pos);
                $pos += $n;
            }

            if ($pos >= $len) {
                $this->pos = $len;
                $this->unexpectedEnd();
            }

            if ('"' === $src[$pos]) {
                if ('' !== $fragment) {
                    $this->tokens[] = new Token(TokenTypeEnum::StringFragment, $fragment, $fragLine, $fragColumn);
                }

                $this->tokens[] = new Token(TokenTypeEnum::StringEnd, '"', $this->line, $pos - $this->lineStart + 1);
                $this->pos      = $pos + 1;

                return false;
            }

            // backslash escape
            if ($pos + 1 >= $len) {
                $this->pos = $len;
                $this->unexpectedEnd();
            }

            $e = $src[$pos + 1];
            if (isset(self::SIMPLE_ESCAPES[$e])) {
                $fragment .= self::SIMPLE_ESCAPES[$e];
                $pos += 2;

                continue;
            }

            if ('(' === $e) {
                if ('' !== $fragment) {
                    $this->tokens[] = new Token(TokenTypeEnum::StringFragment, $fragment, $fragLine, $fragColumn);
                }

                $this->tokens[] = new Token(TokenTypeEnum::InterpStart, '\(', $this->line, $pos - $this->lineStart + 1);
                $this->pos      = $pos + 2;

                return true;
            }

            if ('u' === $e) {
                $pos = $this->unicodeEscape($pos, $fragment);

                continue;
            }

            $this->escapeError('Invalid escape', $pos, 1);
        }
    }

    /**
     * Decode `\uXXXX` (with surrogate pairing) at $pos, append UTF-8 to $out, return the next offset.
     */
    private function unicodeEscape(int $pos, string &$out): int
    {
        $code = $this->hex4($pos);
        $next = $pos + 6;
        if ($code >= 0xD800 && $code <= 0xDBFF) {
            if ($next + 1 < $this->len && '\\' === $this->src[$next] && 'u' === $this->src[$next + 1]) {
                $low = $this->hex4($next);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $out .= $this->utf8(0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00));

                    return $next + 6;
                }
            }

            $out .= "\u{FFFD}";

            return $next;
        }

        if ($code >= 0xDC00 && $code <= 0xDFFF) {
            $out .= "\u{FFFD}";

            return $next;
        }

        $out .= $this->utf8($code);

        return $next;
    }

    /**
     * Value of the four hex digits after the `\u` at $pos, throwing jq's errors when malformed.
     */
    private function hex4(int $pos): int
    {
        $available = min(4, strcspn($this->src, '"\\', $pos + 2));
        if ($available < 4) {
            $this->escapeError('Invalid \uXXXX escape', $pos, 1 + $available);
        }

        $hex = substr($this->src, $pos + 2, 4);
        if (4 !== strspn($hex, self::HEX)) {
            $this->escapeError('Invalid characters in \uXXXX escape', $pos, 5);
        }

        return (int)hexdec($hex);
    }

    private function utf8(int $code): string
    {
        if ($code < 0x80) {
            return pack('C', $code);
        }

        if ($code < 0x800) {
            return pack('CC', 0xC0 | ($code >> 6), 0x80 | ($code & 0x3F));
        }

        if ($code < 0x10000) {
            return pack('CCC', 0xE0 | ($code >> 12), 0x80 | (($code >> 6) & 0x3F), 0x80 | ($code & 0x3F));
        }

        return pack('CCCC', 0xF0 | ($code >> 18), 0x80 | (($code >> 12) & 0x3F), 0x80 | (($code >> 6) & 0x3F), 0x80 | ($code & 0x3F));
    }

    private function countNewlines(string $chunk, int $offset): void
    {
        $count = substr_count($chunk, "\n");
        if (0 === $count) {
            return;
        }

        $this->line += $count;
        $this->lineStart = $offset + (int)strrpos($chunk, "\n") + 1;
    }

    /**
     * Throw jq's string-escape error. $pos is the offset of the backslash; $body is the number of
     * bytes after it that belong to the offending escape (the quoted snippet and the reported column).
     */
    private function escapeError(string $message, int $pos, int $body): never
    {
        throw new JqCompileException(\sprintf(
            "%s at line 1, column %d (while parsing '\"\\%s\"') at <top-level>, line %d, column %d:",
            $message,
            $body + 3,
            substr($this->src, $pos + 1, $body),
            $this->line,
            $pos - $this->lineStart + 1,
        ));
    }

    private function unexpectedEnd(): never
    {
        throw new JqCompileException(\sprintf(
            'syntax error, unexpected end of file at <top-level>, line %d, column %d:',
            $this->line,
            $this->pos - $this->lineStart + 1,
        ));
    }

    private function invalidCharacter(int $line, int $column): never
    {
        throw new JqCompileException(\sprintf(
            'syntax error, unexpected INVALID_CHARACTER at <top-level>, line %d, column %d:',
            $line,
            $column,
        ));
    }
}
