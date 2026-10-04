<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Token;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;

/**
 * The YAML scanner: a byte-oriented port of the libyaml scanner as used by go-yaml (and so by the
 * reference yq), including the comment handling of go-yaml. The parser pulls tokens with peek() and
 * skip() and reads the head, line and foot comment buffers that peeking fills.
 *
 * Input must come from prepare(): BOM stripped, validated, line breaks normalised to LF. The buffer
 * is padded with NUL bytes, which validation guarantees cannot occur in real input, so a NUL means
 * end of stream and look-ahead needs no bounds checks.
 *
 * Comment rules: a comment on the line of a token is that token line comment; a comment block
 * directly under content and followed by a blank line or the end of the stream, or dedented below the
 * current block, is a foot comment of the preceding token; any other block is a head comment of the
 * next token. The scanner runs two tokens ahead of the parser so foot comments are known when the
 * token they trail is consumed.
 */
final class Scanner
{
    private const string NON_PRINTABLE = '/[^\x09\x0A\x0D\x20-\x7E\x{85}\x{A0}-\x{D7FF}\x{E000}-\x{FEFE}\x{FF00}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    /** The first line of a block-context plain scalar: runs of non-blank bytes, a colon not followed by a blank, and inner blanks not followed by a hash. */
    private const string PLAIN_LINE = '/\G(?:[^ \t\n\0:]++|:(?![ \t\n\0]))++(?:[ \t]++(?!#)(?:[^ \t\n\0:]++|:(?![ \t\n\0]))++)*+/';

    /** First bytes that can only start a plain scalar, so no indicator test is needed. */
    private const array PLAIN_START = [
        'a' => true, 'b' => true, 'c' => true, 'd' => true, 'e' => true, 'f' => true, 'g' => true, 'h' => true, 'i' => true,
        'j' => true, 'k' => true, 'l' => true, 'm' => true, 'n' => true, 'o' => true, 'p' => true, 'q' => true, 'r' => true,
        's' => true, 't' => true, 'u' => true, 'v' => true, 'w' => true, 'x' => true, 'y' => true, 'z' => true,
        'A' => true, 'B' => true, 'C' => true, 'D' => true, 'E' => true, 'F' => true, 'G' => true, 'H' => true, 'I' => true,
        'J' => true, 'K' => true, 'L' => true, 'M' => true, 'N' => true, 'O' => true, 'P' => true, 'Q' => true, 'R' => true,
        'S' => true, 'T' => true, 'U' => true, 'V' => true, 'W' => true, 'X' => true, 'Y' => true, 'Z' => true,
        '0' => true, '1' => true, '2' => true, '3' => true, '4' => true, '5' => true, '6' => true, '7' => true, '8' => true,
        '9' => true,
    ];

    private const string WORD_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    private const string URI_CHARS = self::WORD_CHARS . ';/?:@&=+.%!~*()' . "'" . '$';

    public string $headComment = '';

    public string $lineComment = '';

    public string $footComment = '';

    public string $stemComment = '';

    private string $s;

    private readonly int $n;

    private readonly bool $mb;

    private int $p = 0;

    private int $line = 0;

    private int $ls = 0;

    private int $flowLevel = 0;

    private int $indent = -1;

    /** @var list<int> */
    private array $indents = [];

    private bool $simpleKeyAllowed = false;

    /** @var array<int, bool> */
    private array $skPossible = [];

    /** @var array<int, bool> */
    private array $skRequired = [];

    /** @var array<int, int> */
    private array $skToken = [];

    /** @var array<int, int> */
    private array $skIndex = [];

    /** @var array<int, int> */
    private array $skLine = [];

    /** @var array<int, int> */
    private array $skColumn = [];

    /** @var array<int, int> */
    private array $keysByToken = [];

    /** @var list<ScanToken> */
    private array $tokens = [];

    private int $head = 0;

    private int $parsed = 0;

    private bool $tokenAvailable = false;

    private bool $streamStartProduced = false;

    private int $newlines = 0;

    /** @var list<ScanComment> */
    private array $comments = [];

    private int $commentsHead = 0;

    /** @var list<ScanComment>|null */
    private ?array $log = null;

    /**
     * Line break offsets (in the normalised text) that were CRLF in the source.
     *
     * @var array<int, true>
     */
    private array $crlf = [];

    /**
     * @throws YamlSyntaxException
     */
    public function __construct(string $source)
    {
        $prepared = self::normalise($source, $this->crlf);
        $this->n  = \strlen($prepared);
        $this->s  = $prepared . "\0\0\0\0";
        $this->mb = 1 === preg_match('/[\x80-\xFF]/', $prepared);
    }

    /**
     * Strips a UTF-8 BOM, rejects invalid UTF-8 and non-printable characters, and normalises CRLF, CR
     * and NEL to LF.
     *
     * @throws YamlSyntaxException
     */
    public static function prepare(string $yaml): string
    {
        $crlf = [];

        return self::normalise($yaml, $crlf);
    }

    /**
     * The next token, with the comments that belong to it moved into the comment buffers.
     *
     * @throws YamlSyntaxException
     */
    public function peek(): ScanToken
    {
        if (!$this->tokenAvailable) {
            $this->fetchMoreTokens();
        }

        $token = $this->tokens[$this->head];
        // Inlined guard: most peeks find no comment waiting (benchmark yq:identity-medium).
        if ($this->commentsHead < \count($this->comments)) {
            $this->unfoldComments($token);
        }

        return $token;
    }

    public function skip(): void
    {
        $this->tokenAvailable = false;
        ++$this->parsed;
        ++$this->head;
        if ($this->head >= 512) {
            $this->tokens = \array_slice($this->tokens, $this->head - 2);
            $this->head   = 2;
        }
    }

    /**
     * Starts keeping a copy of every comment found, for {@see loggedComments()}.
     */
    public function logComments(): void
    {
        $this->log = [];
    }

    /**
     * @return list<ScanComment> the comments found so far, in scan order; empty unless logComments() ran
     */
    public function loggedComments(): array
    {
        return $this->log ?? [];
    }

    /**
     * Does the work of prepare() and, when the text has comments, collects in crlf the offsets of the
     * line breaks that were CRLF, because go-yaml sees such a break as two when it looks ahead for
     * comments.
     *
     * @param array<int, true> $crlf
     *
     * @throws YamlSyntaxException
     */
    private static function normalise(string $yaml, array &$crlf): string
    {
        if (str_starts_with($yaml, "\xEF\xBB\xBF")) {
            $yaml = substr($yaml, 3);
        }

        $found = preg_match(self::NON_PRINTABLE, $yaml, $match, \PREG_OFFSET_CAPTURE);
        if (false === $found) {
            throw new YamlSyntaxException('invalid leading UTF-8 octet', 1, 0);
        }

        if (1 === $found) {
            $offset = $match[0][1];

            throw new YamlSyntaxException('control characters are not allowed', 1 + substr_count($yaml, "\n", 0, $offset), 0);
        }

        if (str_contains($yaml, "\xC2\x85")) {
            $yaml = str_replace("\xC2\x85", "\n", $yaml);
        }

        if (str_contains($yaml, "\r")) {
            if (str_contains($yaml, '#') && str_contains($yaml, "\r\n")) {
                $out   = '';
                $parts = preg_split('/(\r\n|\r)/', $yaml, -1, \PREG_SPLIT_DELIM_CAPTURE);
                foreach ((array)$parts as $i => $part) {
                    if (1 === $i % 2) {
                        if ("\r\n" === $part) {
                            $crlf[\strlen($out)] = true;
                        }

                        $out .= "\n";
                    } else {
                        $out .= $part;
                    }
                }

                return $out;
            }

            $yaml = str_replace(["\r\n", "\r"], "\n", $yaml);
        }

        return $yaml;
    }

    // ---------------------------------------------------------------- comments

    private function unfoldComments(ScanToken $token): void
    {
        $count = \count($this->comments);
        while ($this->commentsHead < $count) {
            $comment = $this->comments[$this->commentsHead];
            if ($token->startIndex < $comment->tokenIndex) {
                break;
            }

            if ('' !== $comment->head) {
                if (ScanToken::BLOCK_END === $token->type) {
                    break;
                }

                $this->headComment = '' === $this->headComment ? $comment->head : $this->headComment . "\n" . $comment->head;
            }

            if ('' !== $comment->foot) {
                $this->footComment = '' === $this->footComment ? $comment->foot : $this->footComment . "\n" . $comment->foot;
            }

            if ('' !== $comment->line) {
                $this->lineComment = '' === $this->lineComment ? $comment->line : $this->lineComment . "\n" . $comment->line;
            }

            $comment->consume();
            ++$this->commentsHead;
        }

        if ($this->commentsHead >= 64) {
            $this->comments     = \array_slice($this->comments, $this->commentsHead);
            $this->commentsHead = 0;
        }
    }

    /**
     * A comment on the same line, after the token just fetched.
     */
    private function scanLineComment(int $tokenIndex): void
    {
        if ($this->newlines > 0) {
            return;
        }

        $s = $this->s;
        $p = $this->p;
        $q = $p + strspn($s, " \t", $p);
        if ('#' !== $s[$q] || $q - $p >= 512) {
            return;
        }

        $e = strpos($s, "\n", $q);
        if (false === $e) {
            $e = $this->n;
        }

        $text = substr($s, $q, $e - $q);
        $sc   = $this->colAt($q, $this->ls);

        $this->p          = $e;
        $this->newlines   = 0;
        $this->addComment(new ScanComment($e, $tokenIndex, $q, $this->line, $sc, $e + \strlen($text), $this->line, $sc + \strlen($text), '', $text, ''));
    }

    /**
     * Comment lines found while skipping to the next token; the caller stands on the first hash.
     */
    private function scanComments(int $scanIndex): void
    {
        $s          = $this->s;
        $last       = \count($this->tokens) - 1;
        $tok        = $this->tokens[$last];
        $afterValue = ScanToken::VALUE === $tok->type;
        if (ScanToken::FLOW_ENTRY === $tok->type && $last > 0) {
            $tok = $this->tokens[$last - 1];
        }

        $tokenMark   = $tok->startIndex;
        $nextIndent  = max($this->indent, 0);
        $recentEmpty = false;
        $firstEmpty  = $this->newlines <= 1;
        $col0        = $this->col();

        $footLine = $this->line - $this->newlines + 1;
        if (0 === $this->newlines && $col0 > 1) {
            ++$footLine;
        }

        $text         = '';
        $startIdx     = 0;
        $startLine    = 0;
        $startCol     = 0;
        $line         = $this->line;
        $column       = $col0;
        $q            = $this->p;
        $peek         = 0;
        $afterComment = false;
        $flow         = $this->flowLevel > 0;

        $virtual  = false;
        $realLine = $this->line;
        while ($peek < 512) {
            ++$column;
            $c = $virtual ? "\n" : $s[$q];
            if (' ' === $c || "\t" === $c) {
                ++$q;
                ++$peek;

                continue;
            }

            if ($afterComment && "\n" === $c) {
                $afterComment = false;
                ++$peek;
                $column = 0;
                ++$line;
                if (isset($this->crlf[$q])) {
                    $virtual = true;
                } else {
                    ++$q;
                    ++$realLine;
                }

                continue;
            }

            $closeFlow = $flow && (']' === $c || '}' === $c);
            if ($closeFlow || "\n" === $c || "\0" === $c) {
                if ($closeFlow || !$recentEmpty) {
                    if ($closeFlow || ($firstEmpty && $startLine === $footLine && (!$afterValue || $startCol - 1 < $nextIndent))) {
                        if ('' !== $text) {
                            if ($startCol - 1 < $nextIndent) {
                                $tokenMark = $startIdx;
                            }

                            $this->addComment(new ScanComment($scanIndex, $tokenMark, $startIdx, $startLine, $startCol, $q, $line, $column, '', '', $text));
                            $scanIndex        = $q;
                            $tokenMark        = $q;
                            $text             = '';
                        }
                    } elseif ('' !== $text && "\0" !== $c) {
                        $text .= "\n";
                    }
                }

                if ("\n" !== $c) {
                    break;
                }

                $firstEmpty  = false;
                $recentEmpty = true;
                $column      = 0;
                ++$line;
                ++$peek;
                if (!$virtual && isset($this->crlf[$q])) {
                    $virtual = true;
                } else {
                    $virtual = false;
                    ++$q;
                    ++$realLine;
                }

                continue;
            }

            if ('' !== $text && $column - 1 < $nextIndent && $column !== $startCol) {
                $this->addComment(new ScanComment($scanIndex, $tokenMark, $startIdx, $startLine, $startCol, $q, $line, $column, '', '', $text));
                $scanIndex        = $q;
                $tokenMark        = $q;
                $text             = '';
                $footLine         = $line;
            }

            if ('#' !== $c) {
                break;
            }

            if ('' === $text) {
                $startIdx  = $q;
                $startLine = $line;
                $startCol  = $column;
            } else {
                $text .= "\n";
            }

            $recentEmpty = false;
            $e           = strpos($s, "\n", $q);
            if (false === $e) {
                $e = $this->n;
            }

            $text .= substr($s, $q, $e - $q);
            if ($realLine !== $this->line) {
                $this->line = $realLine;
                $this->ls   = (int)strrpos($s, "\n", $q - \strlen($s)) + 1;
            }

            $this->p        = $e;
            $this->newlines = 0;
            $q              = $e;
            $peek           = 0;
            $column         = 0;
            $afterComment   = true;
        }

        if ('' !== $text) {
            $this->addComment(new ScanComment($scanIndex, $startIdx, $startIdx, $startLine, $startCol, $q - 1, $line, $column, $text, '', ''));
        }
    }

    private function addComment(ScanComment $comment): void
    {
        $this->comments[] = $comment;
        if (null !== $this->log) {
            $this->log[] = clone $comment;
        }
    }

    // ---------------------------------------------------------------- positions and errors

    private function col(): int
    {
        return $this->colAt($this->p, $this->ls);
    }

    private function colAt(int $index, int $lineStart): int
    {
        $bytes = $index - $lineStart;
        if (!$this->mb || $bytes <= 0) {
            return $bytes;
        }

        return $bytes - (int)preg_match_all('/[\x80-\xBF]/', substr($this->s, $lineStart, $bytes));
    }

    /**
     * @throws YamlSyntaxException
     */
    private function error(string $problem, int $contextLine = -1): never
    {
        $line = $contextLine > 0 ? $contextLine : $this->line;

        throw new YamlSyntaxException($problem, $line + 1, $this->col());
    }

    private function sync(int $p, int $line, int $ls): void
    {
        $this->p    = $p;
        $this->line = $line;
        $this->ls   = $ls;
    }

    // ---------------------------------------------------------------- token queue

    private function append(ScanToken $token): void
    {
        $this->tokens[] = $token;
    }

    private function insert(int $position, ScanToken $token): void
    {
        // Simple keys are almost always inserted just before the last queued token (the key scalar): swap
        // instead of array_splice, which is several times slower (benchmark yq:identity-medium).
        $at   = $this->head + $position;
        $last = \count($this->tokens) - 1;
        if ($at === $last && $last >= 0) {
            $displaced = $this->tokens[$last];
            array_pop($this->tokens);
            $this->tokens[] = $token;
            $this->tokens[] = $displaced;

            return;
        }

        array_splice($this->tokens, $at, 0, [$token]);
    }

    private function fetchMoreTokens(): void
    {
        while (true) {
            $count = \count($this->tokens);
            if ($this->head < $count - 2) {
                $slot = $this->keysByToken[$this->parsed] ?? null;
                if (null === $slot || !$this->simpleKeyIsValid($slot)) {
                    break;
                }
            }

            if ($count > 0 && ScanToken::STREAM_END === $this->tokens[$count - 1]->type) {
                break;
            }

            $this->fetchNextToken();
        }

        $this->tokenAvailable = true;
    }

    private function fetchNextToken(): void
    {
        if (!$this->streamStartProduced) {
            $this->fetchStreamStart();

            return;
        }

        // Hot path (benchmark yq:identity-medium): column maths inlined for ASCII input, a plain scalar
        // dispatched before the general switch, and the line comment probe made only when a comment can follow.
        $scanIndex  = $this->p;
        $scanLine   = $this->line;
        $scanColumn = $this->mb ? $this->colAt($scanIndex, $this->ls) : $scanIndex - $this->ls;

        $this->scanToNextToken($scanIndex);
        if (!$this->mb ? $this->indent > $this->p - $this->ls : $this->indent > $this->col()) {
            $this->unrollIndent($this->col(), $scanIndex, $scanLine, $scanColumn);
        }

        $s = $this->s;
        $p = $this->p;
        $c = $s[$p];
        if ($p >= $this->n) {
            $this->fetchStreamEnd();

            return;
        }

        if (isset(self::PLAIN_START[$c])) {
            $this->fetchPlainScalar();
            $after = $this->s[$this->p];
            if ('#' === $after || ' ' === $after || "\t" === $after) {
                $this->scanLineComment($p);
            }

            return;
        }

        $columnZero = $p === $this->ls;
        if ($columnZero && '%' === $c) {
            $this->fetchDirective();

            return;
        }

        if ($columnZero && ('-' === $c || '.' === $c) && $s[$p + 1] === $c && $s[$p + 2] === $c && $this->blankzAt($p + 3)) {
            $this->fetchDocumentIndicator('-' === $c ? ScanToken::DOCUMENT_START : ScanToken::DOCUMENT_END);

            return;
        }

        $commentMark = $p;
        if (($this->flowLevel > 0 ? ',' : ':') === $c && [] !== $this->tokens) {
            $commentMark = $this->tokens[\count($this->tokens) - 1]->startIndex;
        }

        $this->fetchToken($c, $p);

        if ('-' === $c && ScanToken::BLOCK_ENTRY === $this->tokens[\count($this->tokens) - 1]->type) {
            return;
        }

        $after = $this->s[$this->p];
        if ('#' === $after || ' ' === $after || "\t" === $after) {
            $this->scanLineComment($commentMark);
        }
    }

    private function fetchToken(string $c, int $p): void
    {
        $s    = $this->s;
        $flow = $this->flowLevel > 0;
        switch ($c) {
            case '[':
                $this->fetchFlowCollectionStart(ScanToken::FLOW_SEQUENCE_START);

                return;

            case '{':
                $this->fetchFlowCollectionStart(ScanToken::FLOW_MAPPING_START);

                return;

            case ']':
                $this->fetchFlowCollectionEnd(ScanToken::FLOW_SEQUENCE_END);

                return;

            case '}':
                $this->fetchFlowCollectionEnd(ScanToken::FLOW_MAPPING_END);

                return;

            case ',':
                $this->fetchFlowEntry();

                return;

            case '-':
                if ($this->blankzAt($p + 1)) {
                    $this->fetchBlockEntry();

                    return;
                }

                break;

            case '?':
                if ($flow || $this->blankzAt($p + 1)) {
                    $this->fetchKey();

                    return;
                }

                break;

            case ':':
                if ($flow || $this->blankzAt($p + 1)) {
                    $this->fetchValue();

                    return;
                }

                break;

            case '*':
                $this->fetchAnchor(ScanToken::ALIAS);

                return;

            case '&':
                $this->fetchAnchor(ScanToken::ANCHOR);

                return;

            case '!':
                $this->fetchTag();

                return;

            case '|':
                if (!$flow) {
                    $this->fetchBlockScalar(true);

                    return;
                }

                break;

            case '>':
                if (!$flow) {
                    $this->fetchBlockScalar(false);

                    return;
                }

                break;

            case "'":
                $this->fetchFlowScalar(true);

                return;

            case '"':
                $this->fetchFlowScalar(false);

                return;

            default:
                break;
        }

        $next = $s[$p + 1];
        if (
            (!$this->blankzAt($p) && !str_contains("-?:,[]{}#&*!|>'\"%@`", $c))
            || ('-' === $c && ' ' !== $next && "\t" !== $next)
            || (!$flow && ('?' === $c || ':' === $c) && !$this->blankzAt($p + 1))
        ) {
            $this->fetchPlainScalar();

            return;
        }

        $this->error('found character that cannot start any token');
    }

    private function blankzAt(int $i): bool
    {
        $c = $this->s[$i];

        return ' ' === $c || "\n" === $c || "\0" === $c || "\t" === $c;
    }

    // ---------------------------------------------------------------- indentation and simple keys

    private function scanToNextToken(int $scanIndex): void
    {
        $s = $this->s;
        while (true) {
            $p = $this->p;
            if ($this->flowLevel > 0 || !$this->simpleKeyAllowed) {
                $p += strspn($s, " \t", $p);
            } else {
                $p += strspn($s, ' ', $p);
            }

            $this->p = $p;
            $c       = $s[$p];
            if ('#' === $c) {
                $this->scanComments($scanIndex);
                $p = $this->p;
                $c = $s[$p];
            }

            if ("\n" === $c) {
                $this->p = $p + 1;
                ++$this->line;
                $this->ls = $p + 1;
                ++$this->newlines;
                if (0 === $this->flowLevel) {
                    $this->simpleKeyAllowed = true;
                }

                continue;
            }

            break;
        }
    }

    private function unrollIndent(int $column, int $scanIndex, int $scanLine, int $scanColumn): void
    {
        if ($this->flowLevel > 0) {
            return;
        }

        $blockIndex  = $scanIndex - 1;
        $blockLine   = $scanLine;
        $blockColumn = $scanColumn;

        while ($this->indent > $column) {
            $stop = $blockIndex;
            for ($i = \count($this->comments) - 1; $i >= 0; --$i) {
                $comment = $this->comments[$i];
                if ($comment->endIndex < $stop) {
                    break;
                }

                if ($comment->startColumn === $this->indent + 1) {
                    $blockIndex  = $comment->startIndex;
                    $blockLine   = $comment->startLine;
                    $blockColumn = $comment->startColumn;
                }

                $stop = $comment->scanIndex;
            }

            $this->append(new ScanToken(ScanToken::BLOCK_END, $blockIndex, $blockLine, $blockColumn, $blockIndex, $blockLine, $blockColumn));
            $this->indent = (int)array_pop($this->indents);
        }
    }

    private function rollIndent(int $column, int $number, int $type, int $index, int $line, int $col): void
    {
        if ($this->flowLevel > 0) {
            return;
        }

        if ($this->indent < $column) {
            $this->indents[] = $this->indent;
            $this->indent    = $column;
            $token           = new ScanToken($type, $index, $line, $col, $index, $line, $col);
            if (-1 === $number) {
                $this->append($token);
            } else {
                $this->insert($number - $this->parsed, $token);
            }
        }
    }

    private function saveSimpleKey(): void
    {
        if (!$this->simpleKeyAllowed) {
            return;
        }

        // Inlined col() and removeSimpleKey(); the simple key slot of the current flow level is
        // skPossible[flowLevel] (benchmark yq:identity-medium).
        $column   = $this->mb ? $this->colAt($this->p, $this->ls) : $this->p - $this->ls;
        $i        = $this->flowLevel;
        $required = 0 === $i && $this->indent === $column;
        $number   = $this->parsed + (\count($this->tokens) - $this->head);
        if ($this->skPossible[$i]) {
            if ($this->skRequired[$i]) {
                $this->error("could not find expected ':'", $this->skLine[$i]);
            }

            unset($this->keysByToken[$this->skToken[$i]]);
        }

        $this->skPossible[$i] = true;
        $this->skRequired[$i] = $required;
        $this->skToken[$i]    = $number;
        $this->skIndex[$i]    = $this->p;
        $this->skLine[$i]     = $this->line;
        $this->skColumn[$i]   = $column;

        $this->keysByToken[$number] = $i;
    }

    private function removeSimpleKey(): void
    {
        $i = $this->flowLevel;
        if ($this->skPossible[$i]) {
            if ($this->skRequired[$i]) {
                $this->error("could not find expected ':'", $this->skLine[$i]);
            }

            $this->skPossible[$i] = false;
            unset($this->keysByToken[$this->skToken[$i]]);
        }
    }

    private function simpleKeyIsValid(int $i): bool
    {
        if (!$this->skPossible[$i]) {
            return false;
        }

        if ($this->skLine[$i] < $this->line || $this->skIndex[$i] + 1024 < $this->p) {
            if ($this->skRequired[$i]) {
                $this->error("could not find expected ':'", $this->skLine[$i]);
            }

            $this->skPossible[$i] = false;

            return false;
        }

        return true;
    }

    private function increaseFlowLevel(): void
    {
        $this->skPossible[] = false;
        $this->skRequired[] = false;
        $this->skToken[]    = 0;
        $this->skIndex[]    = 0;
        $this->skLine[]     = 0;
        $this->skColumn[]   = 0;
        ++$this->flowLevel;
    }

    private function decreaseFlowLevel(): void
    {
        if ($this->flowLevel > 0) {
            --$this->flowLevel;
            $last = \count($this->skPossible) - 1;
            unset($this->keysByToken[$this->skToken[$last]]);
            array_pop($this->skPossible);
            array_pop($this->skRequired);
            array_pop($this->skToken);
            array_pop($this->skIndex);
            array_pop($this->skLine);
            array_pop($this->skColumn);
        }
    }

    // ---------------------------------------------------------------- fetchers

    private function fetchStreamStart(): void
    {
        $this->simpleKeyAllowed    = true;
        $this->streamStartProduced = true;
        $this->indent              = -1;
        $this->skPossible          = [false];
        $this->skRequired          = [false];
        $this->skToken             = [0];
        $this->skIndex             = [0];
        $this->skLine              = [0];
        $this->skColumn            = [0];
        $this->append(new ScanToken(ScanToken::STREAM_START, 0, 0, 0, 0, 0, 0));
    }

    private function fetchStreamEnd(): void
    {
        if ($this->p !== $this->ls) {
            ++$this->line;
            $this->ls = $this->p;
        }

        $this->unrollIndent(-1, $this->p, $this->line, 0);
        $this->removeSimpleKey();
        $this->simpleKeyAllowed = false;
        $this->append(new ScanToken(ScanToken::STREAM_END, $this->p, $this->line, 0, $this->p, $this->line, 0));
    }

    private function fetchDirective(): void
    {
        $this->unrollIndent(-1, $this->p, $this->line, 0);
        $this->removeSimpleKey();
        $this->simpleKeyAllowed = false;
        $this->append($this->scanDirective());
    }

    private function fetchDocumentIndicator(int $type): void
    {
        $this->unrollIndent(-1, $this->p, $this->line, 0);
        $this->removeSimpleKey();
        $this->simpleKeyAllowed = false;

        $line = $this->line;
        $from = $this->p;
        $this->p += 3;
        $this->newlines = 0;
        $this->append(new ScanToken($type, $from, $line, 0, $from + 3, $line, 3));
    }

    private function fetchFlowCollectionStart(int $type): void
    {
        $this->saveSimpleKey();
        $this->increaseFlowLevel();
        $this->simpleKeyAllowed = true;
        $this->consumeIndicator($type);
    }

    private function fetchFlowCollectionEnd(int $type): void
    {
        $this->removeSimpleKey();
        $this->decreaseFlowLevel();
        $this->simpleKeyAllowed = false;
        $this->consumeIndicator($type);
    }

    private function fetchFlowEntry(): void
    {
        $this->removeSimpleKey();
        $this->simpleKeyAllowed = true;
        $this->consumeIndicator(ScanToken::FLOW_ENTRY);
    }

    private function fetchBlockEntry(): void
    {
        if (0 === $this->flowLevel) {
            if (!$this->simpleKeyAllowed) {
                $this->error('block sequence entries are not allowed in this context');
            }

            $this->rollIndent($this->col(), -1, ScanToken::BLOCK_SEQUENCE_START, $this->p, $this->line, $this->col());
        }

        $this->removeSimpleKey();
        $this->simpleKeyAllowed = true;
        $this->consumeIndicator(ScanToken::BLOCK_ENTRY);
    }

    private function fetchKey(): void
    {
        if (0 === $this->flowLevel) {
            if (!$this->simpleKeyAllowed) {
                $this->error('mapping keys are not allowed in this context');
            }

            $this->rollIndent($this->col(), -1, ScanToken::BLOCK_MAPPING_START, $this->p, $this->line, $this->col());
        }

        $this->removeSimpleKey();
        $this->simpleKeyAllowed = 0 === $this->flowLevel;
        $this->consumeIndicator(ScanToken::KEY);
    }

    private function fetchValue(): void
    {
        // Inlined validity test and roll-indent guard (benchmark yq:identity-medium).
        $i = $this->flowLevel;
        if (($this->skPossible[$i] && $this->skLine[$i] >= $this->line && $this->skIndex[$i] + 1024 >= $this->p) || $this->simpleKeyIsValid($i)) {
            $number = $this->skToken[$i];
            $index  = $this->skIndex[$i];
            $line   = $this->skLine[$i];
            $column = $this->skColumn[$i];
            $this->insert($number - $this->parsed, new ScanToken(ScanToken::KEY, $index, $line, $column, $index, $line, $column));
            if (0 === $i && $this->indent < $column) {
                $this->rollIndent($column, $number, ScanToken::BLOCK_MAPPING_START, $index, $line, $column);
            }

            $this->skPossible[$i] = false;
            unset($this->keysByToken[$number]);
            $this->simpleKeyAllowed = false;
        } else {
            if (0 === $this->flowLevel) {
                if (!$this->simpleKeyAllowed) {
                    $this->error('mapping values are not allowed in this context');
                }

                $this->rollIndent($this->col(), -1, ScanToken::BLOCK_MAPPING_START, $this->p, $this->line, $this->col());
            }

            $this->simpleKeyAllowed = 0 === $this->flowLevel;
        }

        $this->consumeIndicator(ScanToken::VALUE);
    }

    private function consumeIndicator(int $type): void
    {
        $this->newlines = 0;
        $index          = $this->p;
        $column         = $this->mb ? $this->colAt($index, $this->ls) : $index - $this->ls;
        ++$this->p;
        $this->tokens[] = new ScanToken($type, $index, $this->line, $column, $index + 1, $this->line, $column + 1);
    }

    private function fetchAnchor(int $type): void
    {
        $this->saveSimpleKey();
        $this->simpleKeyAllowed = false;
        $this->append($this->scanAnchor($type));
    }

    private function fetchTag(): void
    {
        $this->saveSimpleKey();
        $this->simpleKeyAllowed = false;
        $this->append($this->scanTag());
    }

    private function fetchBlockScalar(bool $literal): void
    {
        $this->removeSimpleKey();
        $this->simpleKeyAllowed = true;
        $this->append($this->scanBlockScalar($literal));
    }

    private function fetchFlowScalar(bool $single): void
    {
        $this->saveSimpleKey();
        $this->simpleKeyAllowed = false;
        $this->append($this->scanFlowScalar($single));
    }

    private function fetchPlainScalar(): void
    {
        $this->saveSimpleKey();
        $this->simpleKeyAllowed = false;
        $this->tokens[]         = $this->scanPlainScalar();
    }

    // ---------------------------------------------------------------- scanners

    private function scanDirective(): ScanToken
    {
        $s         = $this->s;
        $startIdx  = $this->p;
        $startLine = $this->line;
        $startCol  = $this->col();
        ++$this->p;

        $len = strspn($s, self::WORD_CHARS, $this->p);
        if (0 === $len) {
            $this->error('did not find expected alphabetic or numeric character', $startLine);
        }

        $name = substr($s, $this->p, $len);
        $this->p += $len;
        $this->newlines = 0;
        if (!$this->blankzAt($this->p)) {
            $this->error('found unexpected non-alphabetical character', $startLine);
        }

        $value  = '';
        $suffix = '';
        if ('YAML' === $name) {
            $type = ScanToken::VERSION_DIRECTIVE;
            $this->p += strspn($s, " \t", $this->p);
            $major = $this->scanVersionNumber($startLine);
            if ('.' !== $s[$this->p]) {
                $this->error("did not find expected digit or '.' character", $startLine);
            }

            ++$this->p;
            $minor = $this->scanVersionNumber($startLine);
            $value = $major . '.' . $minor;
        } elseif ('TAG' === $name) {
            $type = ScanToken::TAG_DIRECTIVE;
            $this->p += strspn($s, " \t", $this->p);
            $value = $this->scanTagHandle(true, $startLine);
            if (' ' !== $s[$this->p] && "\t" !== $s[$this->p]) {
                $this->error('did not find expected whitespace', $startLine);
            }

            $this->p += strspn($s, " \t", $this->p);
            $suffix = $this->scanTagUri(true, '', $startLine);
            if (!$this->blankzAt($this->p)) {
                $this->error('did not find expected whitespace or line break', $startLine);
            }
        } else {
            $this->error('found unknown directive name', $startLine);
        }

        $endIdx  = $this->p;
        $endLine = $this->line;
        $endCol  = $this->col();

        $this->p += strspn($s, " \t", $this->p);
        if ('#' === $s[$this->p]) {
            $e       = strpos($s, "\n", $this->p);
            $this->p = false === $e ? $this->n : $e;
        }

        if ("\0" !== $s[$this->p] && "\n" !== $s[$this->p]) {
            $this->error('did not find expected comment or line break', $startLine);
        }

        if ("\n" === $s[$this->p]) {
            ++$this->p;
            ++$this->line;
            $this->ls = $this->p;
            ++$this->newlines;
        }

        return new ScanToken($type, $startIdx, $startLine, $startCol, $endIdx, $endLine, $endCol, $value, $suffix);
    }

    private function scanVersionNumber(int $startLine): int
    {
        $len = strspn($this->s, '0123456789', $this->p);
        if (0 === $len) {
            $this->error('did not find expected version number', $startLine);
        }

        if ($len > 9) {
            $this->error('found extremely long version number', $startLine);
        }

        $value = (int)substr($this->s, $this->p, $len);
        $this->p += $len;

        return $value;
    }

    private function scanAnchor(int $type): ScanToken
    {
        $s         = $this->s;
        $startIdx  = $this->p;
        $startLine = $this->line;
        $startCol  = $this->col();
        ++$this->p;
        $len = strcspn($s, " \t\n\0,[]{}", $this->p);
        if ($len > 0 && ':' === $s[$this->p + $len - 1] && $this->blankzAt($this->p + $len)) {
            --$len;
        }

        if (0 === $len) {
            $this->error('did not find expected alphabetic or numeric character', $startLine);
        }

        $name = substr($s, $this->p, $len);
        $this->p += $len;
        $this->newlines = 0;

        return new ScanToken($type, $startIdx, $startLine, $startCol, $this->p, $this->line, $this->col(), $name);
    }

    private function scanTag(): ScanToken
    {
        $s              = $this->s;
        $startIdx       = $this->p;
        $startLine      = $this->line;
        $startCol       = $this->col();
        $this->newlines = 0;

        if ('<' === $s[$this->p + 1]) {
            $handle = '';
            $this->p += 2;
            $suffix = $this->scanTagUri(true, '', $startLine);
            if ('>' !== $s[$this->p]) {
                $this->error("did not find the expected '>'", $startLine);
            }

            ++$this->p;
        } else {
            $handle = $this->scanTagHandle(false, $startLine);
            if (\strlen($handle) > 1 && '!' === $handle[0] && '!' === $handle[\strlen($handle) - 1]) {
                $suffix = $this->scanTagUri(false, '', $startLine);
            } else {
                $suffix = $this->scanTagUri(false, $handle, $startLine);
                $handle = '!';
                if ('' === $suffix) {
                    $handle = '';
                    $suffix = '!';
                }
            }
        }

        if (!$this->blankzAt($this->p) && ($this->flowLevel <= 0 || ',' !== $s[$this->p])) {
            $this->error('did not find expected whitespace or line break', $startLine);
        }

        return new ScanToken(ScanToken::TAG, $startIdx, $startLine, $startCol, $this->p, $this->line, $this->col(), $handle, $suffix);
    }

    private function scanTagHandle(bool $directive, int $startLine): string
    {
        $s = $this->s;
        if ('!' !== $s[$this->p]) {
            $this->error("did not find expected '!'", $startLine);
        }

        $len    = 1 + strspn($s, self::WORD_CHARS, $this->p + 1);
        $handle = substr($s, $this->p, $len);
        $this->p += $len;
        if ('!' === $s[$this->p]) {
            $handle .= '!';
            ++$this->p;
        } elseif ($directive && '!' !== $handle) {
            $this->error("did not find expected '!'", $startLine);
        }

        return $handle;
    }

    private function scanTagUri(bool $uriChar, string $head, int $startLine): string
    {
        $s      = $this->s;
        $result = \strlen($head) > 1 ? substr($head, 1) : '';
        $length = \strlen($head);
        $set    = $uriChar ? self::URI_CHARS . ',[]' : self::URI_CHARS;
        while (true) {
            $len = strspn($s, $set, $this->p);
            if (0 === $len) {
                break;
            }

            $chunk = substr($s, $this->p, $len);
            $this->p += $len;
            $length  += $len;
            $result .= $this->decodeUriEscapes($chunk, $startLine);
        }

        if (0 === $length) {
            $this->error('did not find expected tag URI', $startLine);
        }

        return $result;
    }

    private function decodeUriEscapes(string $chunk, int $startLine): string
    {
        if (!str_contains($chunk, '%')) {
            return $chunk;
        }

        if (1 === preg_match('/%(?![0-9A-Fa-f]{2})/', $chunk)) {
            $this->error('did not find URI escaped octet', $startLine);
        }

        return rawurldecode(str_replace('+', '%2B', $chunk));
    }

    private function scanPlainScalar(): ScanToken
    {
        $s            = $this->s;
        $flow         = $this->flowLevel > 0;
        $indent       = $this->indent + 1;
        $p            = $this->p;
        $line         = $this->line;
        $ls           = $this->ls;
        $stop         = $flow ? " \t\n\0:,[]{}" : " \t\n\0:";
        $startIdx     = $p;
        $startLine    = $line;
        $mb           = $this->mb;
        $startCol     = $mb ? $this->colAt($p, $ls) : $p - $ls;
        $eIdx         = $p;
        $eLine        = $line;
        $eLs          = $ls;
        $out          = '';
        $leading      = false;
        $leadingBreak = '';
        $trailing     = '';
        $white        = '';

        // Block context fast path (benchmark yq:identity-medium): take the whole first line of the scalar
        // with one regex instead of the per-chunk loop below, then resume at the whitespace handling.
        $pre = false;
        if (!$flow && 1 === preg_match(self::PLAIN_LINE, $s, $line1, 0, $p)) {
            $out  = $line1[0];
            $p += \strlen($out);
            $eIdx = $p;
            $pre  = true;
        }

        while (true) {
            if (!$pre && $p === $ls && ('---' === substr($s, $p, 3) || '...' === substr($s, $p, 3)) && $this->blankzAt($p + 3)) {
                break;
            }

            if (!$pre && '#' === $s[$p]) {
                break;
            }

            while (!$pre) {
                $c = $s[$p];
                if (' ' === $c || "\n" === $c || "\0" === $c || "\t" === $c) {
                    break;
                }

                if (':' === $c) {
                    $next = $s[$p + 1];
                    if ($flow && str_contains(',?[]{}', $next)) {
                        $this->sync($p, $line, $ls);
                        $this->error("found unexpected ':'", $startLine);
                    }

                    if (' ' === $next || "\n" === $next || "\0" === $next || "\t" === $next) {
                        break;
                    }
                } elseif ($flow && (',' === $c || '[' === $c || ']' === $c || '{' === $c || '}' === $c)) {
                    break;
                }

                if ($leading) {
                    if ("\n" === $leadingBreak) {
                        $out .= '' === $trailing ? ' ' : $trailing;
                    } else {
                        $out .= $leadingBreak . $trailing;
                    }

                    $leadingBreak = '';
                    $trailing     = '';
                    $leading      = false;
                } elseif ('' !== $white) {
                    $out .= $white;
                    $white = '';
                }

                $len = ':' === $c ? 1 : strcspn($s, $stop, $p);
                $out .= substr($s, $p, $len);
                $p += $len;
                $eIdx  = $p;
                $eLine = $line;
                $eLs   = $ls;
            }

            $pre = false;
            $c   = $s[$p];
            if (' ' !== $c && "\n" !== $c && "\t" !== $c) {
                break;
            }

            while (true) {
                $c = $s[$p];
                if (' ' === $c && !$leading) {
                    $k = strspn($s, ' ', $p);
                    $white .= str_repeat(' ', $k);
                    $p += $k;
                } elseif (' ' === $c) {
                    $p += strspn($s, ' ', $p);
                } elseif ("\t" === $c) {
                    if ($leading && $this->colAt($p, $ls) < $indent) {
                        $this->sync($p, $line, $ls);
                        $this->error('found a tab character that violates indentation', $startLine);
                    }

                    if (!$leading) {
                        $white .= $c;
                    }

                    ++$p;
                } elseif ("\n" === $c) {
                    if (!$leading) {
                        $white        = '';
                        $leadingBreak = "\n";
                        $leading      = true;
                    } else {
                        $trailing .= "\n";
                    }

                    ++$p;
                    ++$line;
                    $ls = $p;
                } else {
                    break;
                }
            }

            if (!$flow && ($mb ? $this->colAt($p, $ls) : $p - $ls) < $indent) {
                break;
            }
        }

        $this->p    = $p;
        $this->line = $line;
        $this->ls   = $ls;
        if ($leading) {
            $this->newlines         = 1 + substr_count($trailing, "\n");
            $this->simpleKeyAllowed = true;
        } else {
            $this->newlines = 0;
        }

        return new ScanToken(ScanToken::SCALAR, $startIdx, $startLine, $startCol, $eIdx, $eLine, $mb ? $this->colAt($eIdx, $eLs) : $eIdx - $eLs, $out, '', ScanToken::PLAIN);
    }

    private function scanFlowScalar(bool $single): ScanToken
    {
        $s         = $this->s;
        $p         = $this->p;
        $line      = $this->line;
        $ls        = $this->ls;
        $startIdx  = $p;
        $startLine = $line;
        $startCol  = $this->colAt($p, $ls);
        $quote     = $single ? "'" : '"';
        $stop      = $single ? "' \t\n\0" : "\"\\ \t\n\0";
        ++$p;
        $out          = '';
        $white        = '';
        $leadingBreak = '';
        $trailing     = '';
        $brk          = 0;

        while (true) {
            if ($p === $ls && ('---' === substr($s, $p, 3) || '...' === substr($s, $p, 3)) && $this->blankzAt($p + 3)) {
                $this->sync($p, $line, $ls);
                $this->error('found unexpected document indicator', $startLine);
            }

            if ($p >= $this->n) {
                $this->sync($p, $line, $ls);
                $this->error('found unexpected end of stream', $startLine);
            }

            $leading = false;
            while (true) {
                $c = $s[$p];
                if (' ' === $c || "\n" === $c || "\0" === $c || "\t" === $c) {
                    break;
                }

                if ($single && "'" === $c && "'" === $s[$p + 1]) {
                    $out .= "'";
                    $p += 2;
                    $brk = 0;

                    continue;
                }

                if ($c === $quote) {
                    break;
                }

                if (!$single && '\\' === $c) {
                    if ("\n" === $s[$p + 1]) {
                        $p += 2;
                        ++$line;
                        $ls      = $p;
                        $leading = true;
                        ++$brk;

                        break;
                    }

                    $this->sync($p, $line, $ls);
                    $p   = $this->scanEscape($p, $out, $startLine);
                    $brk = 0;

                    continue;
                }

                $len = strcspn($s, $stop, $p);
                $out .= substr($s, $p, $len);
                $p += $len;
                $brk = 0;
            }

            if ($s[$p] === $quote) {
                break;
            }

            while (true) {
                $c = $s[$p];
                if (' ' === $c || "\t" === $c) {
                    if (!$leading) {
                        $white .= $c;
                        $brk = 0;
                    }

                    ++$p;
                } elseif ("\n" === $c) {
                    if (!$leading) {
                        $white        = '';
                        $leadingBreak = "\n";
                        $leading      = true;
                    } else {
                        $trailing .= "\n";
                    }

                    ++$p;
                    ++$line;
                    ++$brk;
                    $ls = $p;
                } else {
                    break;
                }
            }

            if ($leading) {
                if ("\n" === $leadingBreak) {
                    $out .= '' === $trailing ? ' ' : $trailing;
                } else {
                    $out .= $leadingBreak . $trailing;
                }

                $leadingBreak = '';
                $trailing     = '';
            } else {
                $out .= $white;
                $white = '';
            }
        }

        ++$p;
        $this->sync($p, $line, $ls);
        $this->newlines = 0;

        return new ScanToken(ScanToken::SCALAR, $startIdx, $startLine, $startCol, $p, $line, $this->colAt($p, $ls), $out, '', $single ? ScanToken::SINGLE : ScanToken::DOUBLE);
    }

    /**
     * Decodes the escape sequence at p (a backslash) into out; returns the position after it.
     */
    private function scanEscape(int $p, string &$out, int $startLine): int
    {
        $s    = $this->s;
        $code = $s[$p + 1];
        $len  = 0;
        switch ($code) {
            case '0':
                $out .= "\0";

                break;

            case 'a':
                $out .= "\x07";

                break;

            case 'b':
                $out .= "\x08";

                break;

            case 't':
            case "\t":
                $out .= "\t";

                break;

            case 'n':
                $out .= "\n";

                break;

            case 'v':
                $out .= "\x0B";

                break;

            case 'f':
                $out .= "\x0C";

                break;

            case 'r':
                $out .= "\r";

                break;

            case 'e':
                $out .= "\x1B";

                break;

            case ' ':
                $out .= ' ';

                break;

            case '"':
                $out .= '"';

                break;

            case '/':
                $out .= '/';

                break;

            case '\\':
                $out .= '\\';

                break;

            case 'N':
                $out .= "\xC2\x85";

                break;

            case '_':
                $out .= "\xC2\xA0";

                break;

            case 'L':
                $out .= "\xE2\x80\xA8";

                break;

            case 'P':
                $out .= "\xE2\x80\xA9";

                break;

            case 'x':
                $len = 2;

                break;

            case 'u':
                $len = 4;

                break;

            case 'U':
                $len = 8;

                break;

            default:
                $this->error('found unknown escape character', $startLine);
        }

        $p += 2;
        if (0 === $len) {
            return $p;
        }

        $hex = substr($s, $p, $len);
        if (\strlen($hex) !== $len || !ctype_xdigit($hex)) {
            $this->error('did not find expected hexdecimal number', $startLine);
        }

        $value = (int)hexdec($hex);
        if (($value >= 0xD800 && $value <= 0xDFFF) || $value > 0x10FFFF) {
            $this->error('found invalid Unicode character escape code', $startLine);
        }

        $out .= $this->utf8($value);

        return $p + $len;
    }

    private function utf8(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp & 0x7F);
        }

        if ($cp < 0x800) {
            return \chr((0xC0 | ($cp >> 6)) & 0xFF) . \chr(0x80 | ($cp & 0x3F));
        }

        if ($cp < 0x10000) {
            return \chr((0xE0 | ($cp >> 12)) & 0xFF) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
        }

        return \chr((0xF0 | ($cp >> 18)) & 0xFF) . \chr(0x80 | (($cp >> 12) & 0x3F)) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
    }

    private function scanBlockScalar(bool $literal): ScanToken
    {
        $s         = $this->s;
        $startIdx  = $this->p;
        $startLine = $this->line;
        $startCol  = $this->col();
        ++$this->p;
        $this->newlines = 0;

        $chomping  = 0;
        $increment = 0;
        $c         = $s[$this->p];
        if ('+' === $c || '-' === $c) {
            $chomping = '+' === $c ? 1 : -1;
            ++$this->p;
            $c = $s[$this->p];
            if ($c >= '0' && $c <= '9') {
                if ('0' === $c) {
                    $this->error('found an indentation indicator equal to 0', $startLine);
                }

                $increment = (int)$c;
                ++$this->p;
            }
        } elseif ($c >= '0' && $c <= '9') {
            if ('0' === $c) {
                $this->error('found an indentation indicator equal to 0', $startLine);
            }

            $increment = (int)$c;
            ++$this->p;
            $c = $s[$this->p];
            if ('+' === $c || '-' === $c) {
                $chomping = '+' === $c ? 1 : -1;
                ++$this->p;
            }
        }

        $this->p += strspn($s, " \t", $this->p);
        if ('#' === $s[$this->p]) {
            $this->scanLineComment($startIdx);
            if ('#' === $s[$this->p]) {
                $e       = strpos($s, "\n", $this->p);
                $this->p = false === $e ? $this->n : $e;
            }
        }

        $c = $s[$this->p];
        if ("\n" !== $c && "\0" !== $c) {
            $this->error('did not find expected comment or line break', $startLine);
        }

        $brk = $this->newlines;
        if ("\n" === $c) {
            ++$this->p;
            ++$this->line;
            $this->ls = $this->p;
            ++$brk;
        }

        $eIdx  = $this->p;
        $eLine = $this->line;
        $eLs   = $this->ls;

        $indent = -1;
        if ($increment > 0) {
            $indent = $this->indent >= 0 ? $this->indent + $increment : $increment;
        }

        $trailing = '';
        $brk += $this->blockScalarBreaks($indent, $trailing, $startLine, $eIdx, $eLine, $eLs);

        $out          = '';
        $leadingBreak = '';
        $leadingBlank = false;
        while ($this->p - $this->ls === $indent && $this->p < $this->n) {
            $p             = $this->p;
            $trailingBlank = ' ' === $s[$p] || "\t" === $s[$p];
            if (!$literal && "\n" === $leadingBreak && !$leadingBlank && !$trailingBlank) {
                if ('' === $trailing) {
                    $out .= ' ';
                }
            } else {
                $out .= $leadingBreak;
            }

            $leadingBreak = '';
            $out .= $trailing;
            $trailing     = '';
            $leadingBlank = $trailingBlank;

            $e = strpos($s, "\n", $p);
            if (false === $e) {
                $e = $this->n;
            }

            $out .= substr($s, $p, $e - $p);
            $this->p = $e;
            $brk     = 0;
            if ("\n" === $s[$e]) {
                $leadingBreak = "\n";
                ++$this->p;
                ++$this->line;
                $this->ls = $this->p;
                ++$brk;
            }

            $brk += $this->blockScalarBreaks($indent, $trailing, $startLine, $eIdx, $eLine, $eLs);
        }

        if (-1 !== $chomping) {
            $out .= $leadingBreak;
        }

        if (1 === $chomping) {
            $out .= $trailing;
        }

        $this->newlines = $brk;

        return new ScanToken(ScanToken::SCALAR, $startIdx, $startLine, $startCol, $eIdx, $eLine, $this->colAt($eIdx, $eLs), $out, '', $literal ? ScanToken::LITERAL : ScanToken::FOLDED);
    }

    /**
     * Consumes indentation and empty lines before the next block scalar line; returns how many line
     * breaks it consumed.
     */
    private function blockScalarBreaks(int &$indent, string &$breaks, int $startLine, int &$eIdx, int &$eLine, int &$eLs): int
    {
        $s         = $this->s;
        $maxIndent = 0;
        $count     = 0;
        $eIdx      = $this->p;
        $eLine     = $this->line;
        $eLs       = $this->ls;
        while (true) {
            $run = strspn($s, ' ', $this->p);
            if ($indent >= 0) {
                $run = min($run, max(0, $indent - ($this->p - $this->ls)));
            }

            $this->p += $run;
            $col = $this->p - $this->ls;
            if ($col > $maxIndent) {
                $maxIndent = $col;
            }

            if (($indent < 0 || $col < $indent) && "\t" === $s[$this->p]) {
                $this->error('found a tab character where an indentation space is expected', $startLine);
            }

            if ("\n" !== $s[$this->p]) {
                break;
            }

            $breaks .= "\n";
            ++$count;
            ++$this->p;
            ++$this->line;
            $this->ls = $this->p;
            $eIdx     = $this->p;
            $eLine    = $this->line;
            $eLs      = $this->ls;
        }

        if ($indent < 0) {
            $indent = max($maxIndent, $this->indent + 1);
        }

        return $count;
    }
}
