<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use Generator;
use JsonException;
use LTS\PhpXq\Json\Codec\ParseFailure;
use LTS\PhpXq\Json\Codec\Utf8;
use stdClass;

/**
 * JSON decoder reproducing jq's parser (jv_parse.c): the same grammar, the same extensions and the same
 * error messages and positions.
 *
 * Two paths produce identical results. Text that cannot differ between PHP's native json_decode and jq is
 * decoded natively: the pre-check rules out every number whose literal jq would keep or print differently
 * (more than 15 digits, exponents, trailing fraction zeros, magnitudes below 1e-4, negative zero) and
 * anything json_decode refuses (nan, invalid UTF-8, lone surrogates, trailing text, extreme depth). All
 * other text, and every error, goes through the hand written scanner, which is a token level port of jq's
 * state machine so error behaviour matches. Multi value text first tries newline separated chunks
 * natively, which is the common NDJSON shape.
 *
 * RFC 7464 mode ($seq): the generator yields a {@see JsonSyntaxException} object in place of a thrown error
 * when jq would "ignore the parse error" and resynchronise on the next RS; the caller reports it and keeps
 * iterating. Text before the first RS is ignored.
 *
 * @api
 */
final class JsonDecoder implements JsonDecoderInterface
{
    /**
     * Number shapes the native path must not see (outside strings, which are skipped).
     */
    private const string DANGER = '/"(?:[^"\\\]++|\\\.)*+"(*SKIP)(*FAIL)|\d[\d.]{15}|\d[eE]|\.\d*0(?!\d)|\.0000|-0(?![\d.])/s';

    /**
     * The same shapes as DANGER, as whole tokens (strings skipped), plus nan and Infinity.
     */
    private const string NUMBER_TOKEN = '/"(?:[^"\\\]++|\\\.)*+"(*SKIP)(*FAIL)|(?<![\w.+-])(?:(?=-?(?:\d+(?:\.\d+)?[eE]|\d+\.\d*0(?![\d.eE+-])|\d+\.0000|\d[\d.]{15})|-0(?![\d.eE]))-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|-?(?:nan|NaN|Infinity)(?!\w))/s';

    private const string DELIMITERS = " \t\r\n[]{},:\"";

    private const string DELIMITERS_SEQ = " \t\r\n[]{},:\"\x1e";

    private const string CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

    private const string CONTROL_OR_BACKSLASH = self::CONTROL . '\\';

    private const string CONTROL_MESSAGE = 'Invalid string: control characters from U+0000 through U+001F must be escaped';

    private const string PAIR_MESSAGE = 'Invalid \uXXXX\uXXXX surrogate pair escape';

    private const string RS = "\x1e";

    private const string BOM = "\xEF\xBB\xBF";

    /**
     * A string of printable ASCII without escapes, starting at the offset: group 1 is its content.
     */
    private const string SIMPLE_STRING = '/\G"([\x20\x21\x23-\x5b\x5d-\x7e]*+)"/';

    private const int MAX_DEPTH = 10000;

    private const int FAST_DEPTH = 1500;

    private const int KIND_ARRAY = 1;

    private const int KIND_OBJECT = 2;

    private const int KIND_KEY = 3;

    public function decodeOne(string $text): mixed
    {
        $ok    = false;
        $value = $this->fast(str_starts_with($text, self::BOM) ? substr($text, 3) : $text, $ok);
        if ($ok) {
            return $value;
        }

        $values = $this->scan($text, false, 0);

        try {
            if (!$values->valid()) {
                throw new JsonSyntaxException('Expected JSON value');
            }

            $first = $values->current();
            $values->next();
            if ($values->valid()) {
                throw new JsonSyntaxException('Unexpected extra JSON values');
            }
        } catch (JsonSyntaxException $jsonSyntaxException) {
            throw new JsonSyntaxException($jsonSyntaxException->getMessage() . " (while parsing '" . $text . "')", 0, $jsonSyntaxException);
        }

        return $first;
    }

    public function decodeAll(string $text, bool $seq = false): Generator
    {
        $offset = 0;
        $shift  = 0;
        if (!$seq) {
            $ok     = false;
            $offset = str_starts_with($text, self::BOM) ? 3 : 0;
            $shift  = $offset;
            $value  = $this->fast(0 === $shift ? $text : substr($text, $shift), $ok);
            if ($ok) {
                yield $value;

                return;
            }

            $length = \strlen($text);
            if (str_contains($text, "\n")) {
                while ($offset < $length) {
                    $newline = strpos($text, "\n", $offset);
                    $end     = false === $newline ? $length : $newline;
                    $line    = substr($text, $offset, $end - $offset);
                    if ('' === trim($line, " \t\r")) {
                        $offset = $end + 1;

                        continue;
                    }

                    $value = $this->fast($line, $ok);
                    if ($ok) {
                        yield $value;

                        $offset = $end + 1;

                        continue;
                    }

                    // a line the native decoder cannot take (big numbers, 1.0, nan ...): scan just that
                    // line; anything unusual, such as a value continuing on the next line or a syntax
                    // error, is left to the whole text scanner so positions and messages stay exact
                    try {
                        $scanned = iterator_to_array($this->scan($text, false, $offset, $end), false);
                    } catch (JsonSyntaxException) {
                        break;
                    }

                    foreach ($scanned as $item) {
                        yield $item;
                    }

                    $offset = $end + 1;
                }

                if ($offset >= $length) {
                    return;
                }
            }
        }

        // the scanner strips a leading BOM itself, so a restart at the very beginning is a restart at 0
        foreach ($this->scan($text, $seq, $offset === $shift ? 0 : $offset) as $item) {
            yield $item;
        }
    }

    /**
     * @param-out bool $ok
     */
    private function fast(string $text, bool &$ok): mixed
    {
        $ok      = false;
        $numbers = [];
        $source  = $text;
        if (0 !== preg_match(self::DANGER, $text)) {
            // Numbers the native decoder would flatten (1.0, 1e5, 20 digit ids, nan ...) are swapped for
            // placeholder strings, decoded here with the shared number rules, and put back afterwards.
            // The placeholder starts with a NUL, which a string can only carry through a \u0000 escape.
            if (str_contains($text, '\u0000')) {
                return null;
            }

            $source = preg_replace_callback(
                self::NUMBER_TOKEN,
                static function (array $match) use (&$numbers): string {
                    $number = NumberParser::tryParse($match[0]);
                    if (null === $number) {
                        return $match[0];
                    }

                    $numbers[] = $number;

                    return '"\u0000' . (\count($numbers) - 1) . '"';
                },
                $text,
                -1,
                $count,
            );
            if (!\is_string($source) || 0 === $count) {
                return null;
            }
        }

        try {
            $value = json_decode($source, false, self::FAST_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $ok = true;
        if ([] === $numbers) {
            return \is_object($value) || (\is_array($value) && str_contains($source, '{')) ? self::convertPlain($value) : $value;
        }

        if (\is_array($value) || $value instanceof stdClass) {
            return self::convert($value, $numbers);
        }

        if (\is_string($value) && '' !== $value && "\0" === $value[0]) {
            return $numbers[(int)substr($value, 1)];
        }

        return $value;
    }

    /**
     * {@see self::convert()} for text with no number placeholders. Hot path of every large input
     * (bench identity-medium: 12% faster end to end than the placeholder-aware walk): the object test
     * comes first and a member needs one type test, not three.
     */
    private static function convertPlain(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = (array)$value;
            foreach ($members as $key => $member) {
                if (\is_object($member) || \is_array($member)) {
                    $members[$key] = self::convertPlain($member);
                }
            }

            return new JsonObject($members);
        }

        if (\is_array($value)) {
            foreach ($value as $key => $member) {
                if (\is_object($member) || \is_array($member)) {
                    $value[$key] = self::convertPlain($member);
                }
            }
        }

        return $value;
    }

    /**
     * Native decoder output to the value model: objects become JsonObject and number placeholders are
     * replaced by their parsed numbers.
     *
     * @param list<int|float|PreciseNumber> $numbers
     */
    private static function convert(mixed $value, array $numbers): mixed
    {
        if (\is_array($value)) {
            foreach ($value as $key => $member) {
                if (\is_array($member) || $member instanceof stdClass) {
                    $value[$key] = self::convert($member, $numbers);
                } elseif ([] !== $numbers && \is_string($member) && '' !== $member && "\0" === $member[0]) {
                    $value[$key] = $numbers[(int)substr($member, 1)];
                }
            }

            return $value;
        }

        if ($value instanceof stdClass) {
            $members = (array)$value;
            foreach ($members as $key => $member) {
                if (\is_array($member) || $member instanceof stdClass) {
                    $members[$key] = self::convert($member, $numbers);
                } elseif ([] !== $numbers && \is_string($member) && '' !== $member && "\0" === $member[0]) {
                    $members[$key] = $numbers[(int)substr($member, 1)];
                }
            }

            return new JsonObject($members);
        }

        return $value;
    }

    /**
     * jq's parser as a generator: yields each top level value as soon as it is complete. $limit, when not
     * negative, ends the input early (end of a line the caller has already isolated).
     *
     * @return Generator<int, mixed>
     */
    private function scan(string $text, bool $seq, int $i, int $limit = -1): Generator
    {
        $n    = $limit < 0 ? \strlen($text) : $limit;
        $base = 0;
        if (0 === $i && $n > 0 && "\xEF" === $text[0]) {
            if (!str_starts_with($text, "\xEF\xBB\xBF")) {
                throw new JsonSyntaxException('Malformed BOM');
            }

            $i    = 3;
            $base = 3;
        }

        $delimiters = $seq ? self::DELIMITERS_SEQ : self::DELIMITERS;
        /** @var array<int, int> $kinds */
        $kinds = [];
        /** @var array<int, array<array-key, mixed>> $conts */
        $conts = [];
        /** @var array<int, string> $keys */
        $keys      = [];
        $sp        = 0;
        $hasNext   = false;
        $next      = null;
        $pending   = null;
        $waiting   = $seq;
        $abandoned = false;
        $lastWs    = false;

        while (true) {
            try {
                while ($i < $n) {
                    if ($waiting) {
                        $rs = strpos($text, self::RS, $i);
                        if (false === $rs) {
                            $abandoned = '' !== trim(substr($text, $i), " \t\r\n");
                            $i         = $n;

                            break;
                        }

                        $i       = $rs + 1;
                        $waiting = false;

                        continue;
                    }

                    $length = strcspn($text, $delimiters, $i);
                    if ($length > 0) {
                        $pending = substr($text, $i, $length);
                        $i      += $length;
                        if ($i >= $n) {
                            break;
                        }
                    }

                    $c = $text[$i];

                    if ($seq && self::RS === $c) {
                        $topNumber = 0 === $sp && (null !== $pending
                            ? \is_int($number = NumberParser::tryParse($pending)) || \is_float($number) || $number instanceof PreciseNumber
                            : ($hasNext && $this->isNumber($next)));
                        if (!$lastWs && ($sp > 0 || null !== $pending || ($hasNext && $this->isNumber($next)))) {
                            throw new ParseFailure($topNumber ? 'Potentially truncated top-level numeric value' : 'Truncated value', $i + 1, false, true);
                        }

                        if (null !== $pending) {
                            $value = $this->literal($pending, $i + 1, false, true);
                            if ($hasNext) {
                                throw new ParseFailure('Expected separator between values', $i + 1, false, true);
                            }

                            $next    = $value;
                            $hasNext = true;
                            $pending = null;
                        }

                        $emit     = $next;
                        $complete = 0 === $sp && $hasNext;
                        $kinds    = [];
                        $conts    = [];
                        $sp       = 0;
                        $hasNext  = false;
                        $next     = null;
                        ++$i;
                        if ($complete) {
                            yield $emit;
                        }

                        continue;
                    }

                    if (null !== $pending) {
                        $value = \strlen($pending) <= 15 && ctype_digit($pending)
                            ? (int)$pending
                            : $this->literal($pending, $i + 1, false, false);
                        if ($hasNext) {
                            throw new ParseFailure('Expected separator between values', $i + 1);
                        }

                        $next    = $value;
                        $hasNext = true;
                        $pending = null;
                    }

                    $lastWs = false;
                    if (',' === $c) {
                        if (!$hasNext || 0 === $sp) {
                            throw new ParseFailure("Expected value before ','", $i + 1);
                        }

                        $kind = $kinds[$sp - 1];
                        if (self::KIND_ARRAY === $kind) {
                            $conts[$sp - 1][] = $next;
                        } elseif (self::KIND_KEY === $kind) {
                            $conts[$sp - 2][$keys[$sp - 1]] = $next;
                            --$sp;
                        } else {
                            throw new ParseFailure('Objects must consist of key:value pairs', $i + 1);
                        }

                        $hasNext = false;
                        $next    = null;
                        ++$i;
                    } elseif ('"' === $c) {
                        if (0 === $sp && $hasNext) {
                            $emit    = $next;
                            $hasNext = false;
                            $next    = null;

                            yield $emit;
                        }

                        if (1 === preg_match(self::SIMPLE_STRING, $text, $match, 0, $i)) {
                            // printable ASCII without escapes: no unescaping or UTF-8 repair needed
                            if ($hasNext) {
                                throw new ParseFailure('Expected separator between values', $i + \strlen($match[1]) + 2);
                            }

                            $next    = $match[1];
                            $hasNext = true;
                            $i      += \strlen($match[1]) + 2;
                        } else {
                            $start = $i     + 1;
                            $end   = $start + strcspn($text, '"\\', $start);
                            while ($end < $n && '\\' === $text[$end]) {
                                $end += 2;
                                if ($end >= $n) {
                                    break;
                                }

                                $end += strcspn($text, '"\\', $end);
                            }

                            $closed = $end < $n;
                            $end    = min($end, $n);
                            $raw    = substr($text, $start, $end - $start);
                            if ($seq) {
                                $rsAt = strpos($raw, self::RS);
                                if (false !== $rsAt) {
                                    if ($sp > 0 || $rsAt > 0) {
                                        throw new ParseFailure('Truncated value', $start + $rsAt + 1, false, true);
                                    }

                                    $kinds   = [];
                                    $conts   = [];
                                    $sp      = 0;
                                    $hasNext = false;
                                    $next    = null;
                                    $i       = $start + $rsAt + 1;

                                    continue;
                                }
                            }

                            if (!$closed) {
                                throw new ParseFailure('Unfinished string', $n, true);
                            }

                            $string = $this->string($raw, $end + 1);
                            if ($hasNext) {
                                throw new ParseFailure('Expected separator between values', $end + 1);
                            }

                            $next    = $string;
                            $hasNext = true;
                            $i       = $end + 1;
                        }
                    } elseif (':' === $c) {
                        if (!$hasNext) {
                            throw new ParseFailure("Expected string key before ':'", $i + 1);
                        }

                        if (0 === $sp || self::KIND_OBJECT !== $kinds[$sp - 1]) {
                            throw new ParseFailure("':' not as part of an object", $i + 1);
                        }

                        if (!\is_string($next)) {
                            throw new ParseFailure('Object keys must be strings', $i + 1);
                        }

                        $kinds[$sp] = self::KIND_KEY;
                        $keys[$sp]  = $next;
                        $conts[$sp] = [];
                        ++$sp;
                        $hasNext = false;
                        $next    = null;
                        ++$i;
                    } elseif ('[' === $c || '{' === $c) {
                        if ($this->tooDeep($sp)) {
                            throw new ParseFailure('Exceeds depth limit for parsing', $i + 1);
                        }

                        if ($hasNext) {
                            throw new ParseFailure('Expected separator between values', $i + 1);
                        }

                        $kinds[$sp] = '[' === $c ? self::KIND_ARRAY : self::KIND_OBJECT;
                        $conts[$sp] = [];
                        ++$sp;
                        ++$i;
                    } elseif (']' === $c) {
                        if (0 === $sp || self::KIND_ARRAY !== $kinds[$sp - 1]) {
                            throw new ParseFailure("Unmatched ']'", $i + 1);
                        }

                        if ($hasNext) {
                            $conts[$sp - 1][] = $next;
                        } elseif ([] !== $conts[$sp - 1]) {
                            throw new ParseFailure('Expected another array element', $i + 1);
                        }

                        --$sp;
                        $next           = $conts[$sp];
                        $conts[$sp]     = [];
                        $hasNext        = true;
                        ++$i;
                    } elseif ('}' === $c) {
                        if (0 === $sp) {
                            throw new ParseFailure("Unmatched '}'", $i + 1);
                        }

                        if ($hasNext) {
                            if (self::KIND_KEY !== $kinds[$sp - 1]) {
                                throw new ParseFailure('Objects must consist of key:value pairs', $i + 1);
                            }

                            $conts[$sp - 2][$keys[$sp - 1]] = $next;
                            --$sp;
                        } else {
                            if (self::KIND_OBJECT !== $kinds[$sp - 1]) {
                                throw new ParseFailure("Unmatched '}'", $i + 1);
                            }

                            if ([] !== $conts[$sp - 1]) {
                                throw new ParseFailure('Expected another key-value pair', $i + 1);
                            }
                        }

                        --$sp;
                        /** @var array<array-key, mixed> $members */
                        $members    = $conts[$sp];
                        $conts[$sp] = [];
                        $next       = new JsonObject($members);
                        $hasNext    = true;
                        ++$i;
                    } else {
                        $lastWs = true;
                        $i     += 1 + strspn($text, " \t\r\n", $i + 1);
                    }

                    if (0 === $sp && $hasNext) {
                        $emit    = $next;
                        $hasNext = false;
                        $next    = null;

                        yield $emit;
                    }
                }

                if ($waiting) {
                    if ($abandoned) {
                        throw new ParseFailure('Unfinished abandoned text', $n, true);
                    }

                    return;
                }

                if (null !== $pending) {
                    $value = $this->literal($pending, $n, true, false);
                    if ($hasNext) {
                        throw new ParseFailure('Expected separator between values', $n, true);
                    }

                    $next    = $value;
                    $hasNext = true;
                    $pending = null;
                }

                if (0 !== $sp) {
                    throw new ParseFailure('Unfinished JSON term', $n, true);
                }

                if ($seq && $hasNext && !$lastWs && $this->isNumber($next)) {
                    throw new ParseFailure('Potentially truncated top-level numeric value', $n, true);
                }

                if ($hasNext) {
                    yield $next;
                }

                return;
            } catch (ParseFailure $failure) {
                $message = $failure->describe($text, $base);
                if (!$seq) {
                    throw new JsonSyntaxException($message, $failure->getCode(), $failure);
                }

                $kinds   = [];
                $conts   = [];
                $sp      = 0;
                $hasNext = false;
                $next    = null;
                $pending = null;
                $i       = $failure->consumed;
                if ($failure->eof) {
                    yield new JsonSyntaxException($message);

                    return;
                }

                if ($failure->onRs) {
                    $waiting = false;
                } else {
                    $message .= ' (need RS to resync)';
                    $waiting  = true;
                }

                yield new JsonSyntaxException($message);
            }
        }
    }

    private function tooDeep(int $depth): bool
    {
        return $depth >= self::MAX_DEPTH;
    }

    private function isNumber(mixed $value): bool
    {
        return \is_int($value) || \is_float($value) || $value instanceof PreciseNumber;
    }

    /**
     * jq's check_literal for one token: true, false, null, or a number.
     */
    private function literal(string $token, int $consumed, bool $eof, bool $onRs): mixed
    {
        $first = $token[0];
        if ('t' === $first) {
            return 'true' === $token ? true : throw new ParseFailure('Invalid literal', $consumed, $eof, $onRs);
        }

        if ('f' === $first) {
            return 'false' === $token ? false : throw new ParseFailure('Invalid literal', $consumed, $eof, $onRs);
        }

        if ('n' === $first && isset($token[1]) && 'u' === $token[1]) {
            return 'null' === $token ? null : throw new ParseFailure('Invalid literal', $consumed, $eof, $onRs);
        }

        if ("'" === $first) {
            throw new ParseFailure("Invalid string literal; expected \", but got '", $consumed, $eof, $onRs);
        }

        return NumberParser::tryParse($token) ?? throw new ParseFailure('Invalid numeric literal', $consumed, $eof, $onRs);
    }

    /**
     * jq's found_string: resolve escapes, reject raw control characters, replace invalid UTF-8.
     */
    private function string(string $raw, int $consumed): string
    {
        $length = \strlen($raw);
        if (!str_contains($raw, '\\')) {
            if (strcspn($raw, self::CONTROL) < $length) {
                throw new ParseFailure(self::CONTROL_MESSAGE, $consumed);
            }

            return Utf8::sanitize($raw);
        }

        $out = '';
        $p   = 0;
        while ($p < $length) {
            $run = strcspn($raw, self::CONTROL_OR_BACKSLASH, $p);
            if ($run > 0) {
                $out .= substr($raw, $p, $run);
                $p   += $run;
            }

            if ($p >= $length) {
                break;
            }

            if ('\\' !== $raw[$p]) {
                throw new ParseFailure(self::CONTROL_MESSAGE, $consumed);
            }

            ++$p;
            if ($p >= $length) {
                throw new ParseFailure('Expected escape character at end of string', $consumed);
            }

            $escape = $raw[$p];
            ++$p;
            switch ($escape) {
                case '"':
                case '\\':
                case '/':
                    $out .= $escape;

                    break;

                case 'b':
                    $out .= "\x08";

                    break;

                case 'f':
                    $out .= "\x0c";

                    break;

                case 'n':
                    $out .= "\n";

                    break;

                case 'r':
                    $out .= "\r";

                    break;

                case 't':
                    $out .= "\t";

                    break;

                case 'u':
                    if ($p + 4 > $length) {
                        throw new ParseFailure('Invalid \uXXXX escape', $consumed);
                    }

                    $hex = substr($raw, $p, 4);
                    if (!ctype_xdigit($hex)) {
                        throw new ParseFailure('Invalid characters in \uXXXX escape', $consumed);
                    }

                    $codepoint = (int)hexdec($hex);
                    $p        += 4;
                    if ($codepoint >= 0xD800 && $codepoint <= 0xDBFF) {
                        if ($p + 6 > $length || '\\' !== $raw[$p] || 'u' !== $raw[$p + 1]) {
                            throw new ParseFailure(self::PAIR_MESSAGE, $consumed);
                        }

                        $lowHex = substr($raw, $p + 2, 4);
                        $low    = ctype_xdigit($lowHex) ? (int)hexdec($lowHex) : 0;
                        if ($low < 0xDC00 || $low > 0xDFFF) {
                            throw new ParseFailure(self::PAIR_MESSAGE, $consumed);
                        }

                        $p        += 6;
                        $codepoint = 0x10000 + ((($codepoint - 0xD800) << 10) | ($low - 0xDC00));
                    }

                    $out .= Utf8::encode($codepoint);

                    break;

                default:
                    throw new ParseFailure('Invalid escape', $consumed);
            }
        }

        return Utf8::sanitize($out);
    }
}
