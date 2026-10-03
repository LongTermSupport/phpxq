<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * Translates an Oniguruma pattern (jq compiles with ONIG_SYNTAX_PERL_NT, so it is already close to PCRE)
 * into PCRE source for the `/` delimiter.
 *
 * Translations: `\h`/`\H` are plain `h`/`H` (the Perl syntax has no such escape, while PCRE reads them as
 * horizontal space); `{,n}` means `{0,n}`; the delimiter `/` is escaped; duplicate group names are allowed;
 * the POSIX property names PCRE lacks (`\p{Digit}`, `\p{Blank}`, `\p{Punct}`, ...) are mapped, and with the
 * `i` modifier `[:upper:]` and `[:lower:]` match any cased letter. For subjects with non-ASCII text,
 * `\w \W \b \B` are rewritten to Oniguruma's Unicode word definition (letters, marks, decimal and letter
 * numbers, connector punctuation), because PCRE2 before 10.43 leaves combining marks out of `\w`.
 *
 * Known limits: a negated mapped property or `\W` inside a character class cannot be rewritten (the
 * property case is rejected, `\W` keeps PCRE's meaning); Oniguruma-only constructs (absent operator
 * `(?~...)`, other `\p{...}` names PCRE lacks, `\y`/`\Y`) are left to PCRE and fail as invalid regexes.
 *
 * @internal
 */
final class RegexTranslator
{
    private const string HEX = '0-9a-fA-F';

    private const string WORD = '\p{L}\p{M}\p{Nd}\p{Nl}\p{Pc}';

    private const string CASED = '\p{Lu}\p{Ll}\p{Lt}';

    /**
     * Normalised Oniguruma property name => class body, and the body to use for the negation inside a
     * character class (null when there is none).
     */
    private const array PROPERTIES = [
        'alnum'  => ['\p{L}\p{Nl}\p{Nd}', null],
        'ascii'  => ['\x00-\x7f', null],
        'blank'  => ['\h', '\H'],
        'cntrl'  => ['\p{Cc}', '\P{Cc}'],
        'digit'  => ['\p{Nd}', '\P{Nd}'],
        'lower'  => ['\p{Ll}', '\P{Ll}'],
        'punct'  => ['\p{P}', '\P{P}'],
        'space'  => ['\s', '\S'],
        'upper'  => ['\p{Lu}', '\P{Lu}'],
        'word'   => [self::WORD, null],
        'xdigit' => [self::HEX, null],
    ];

    private function __construct()
    {
    }

    /**
     * jq's error for a pattern that does not compile.
     */
    public static function invalid(string $source, string $reason): JqException
    {
        return new JqException($source . ' (at offset 0) is not a valid regex: ' . $reason);
    }

    public static function translate(string $source, bool $extended, bool $unicodeWord, bool $ignoreCase = false): TranslatedPattern
    {
        $length     = \strlen($source);
        $out        = '';
        $names      = [];
        $usesWord   = false;
        $inClass    = false;
        $classStart = 0;
        $i          = 0;

        while ($i < $length) {
            $c = $source[$i];

            if ('\\' === $c) {
                if ($i + 1 >= $length) {
                    throw self::invalid($source, 'end pattern at escape');
                }

                $escape = $source[$i + 1];
                if (('p' === $escape || 'P' === $escape) && 1 === preg_match('/\G\\\([pP])\{(\^?)([^}]*)\}/', $source, $property, 0, $i)) {
                    $negated     = ('P' === $property[1]) !== ('^' === $property[2]);
                    $replacement = self::translateProperty($source, $property[3], $negated, $inClass, $ignoreCase);
                    if (null !== $replacement) {
                        $out .= $replacement;
                        $i   += \strlen($property[0]);

                        continue;
                    }
                }

                if ('Q' === $escape) {
                    $end     = strpos($source, '\E', $i + 2);
                    $quoted  = false === $end ? substr($source, $i + 2) : substr($source, $i + 2, $end - $i - 2);
                    $out    .= '\Q' . str_replace('/', '\E\/\Q', $quoted) . '\E';
                    $i       = false === $end ? $length : $end + 2;

                    continue;
                }

                if (\in_array($escape, ['w', 'W', 'b', 'B'], true) && !$inClass) {
                    $usesWord = true;
                }

                $out .= self::translateEscape($escape, $inClass, $unicodeWord);
                $i   += 2;

                continue;
            }

            if ($inClass) {
                if ('[' === $c && ':' === ($source[$i + 1] ?? '')) {
                    $close = strpos($source, ':]', $i + 2);
                    if (false !== $close) {
                        $bracket = substr($source, $i, $close + 2 - $i);
                        $out    .= $ignoreCase && ('[:upper:]' === $bracket || '[:lower:]' === $bracket) ? self::CASED : $bracket;
                        $i       = $close + 2;

                        continue;
                    }
                }

                if (']' === $c && $i !== $classStart) {
                    $inClass = false;
                }

                $out .= '/' === $c ? '\/' : $c;
                ++$i;

                continue;
            }

            if ('[' === $c) {
                $inClass = true;
                $out    .= '[';
                ++$i;
                if ('^' === ($source[$i] ?? '')) {
                    $out .= '^';
                    ++$i;
                }

                $classStart = $i;

                continue;
            }

            if ('(' === $c) {
                $i = self::translateGroup($source, $i, $out, $names, $extended);

                continue;
            }

            if ('/' === $c) {
                $out .= '\/';
                ++$i;

                continue;
            }

            if ('#' === $c && $extended) {
                $end  = strpos($source, "\n", $i);
                $stop = false === $end ? $length : $end + 1;
                $out .= str_replace('/', '\/', substr($source, $i, $stop - $i));
                $i    = $stop;

                continue;
            }

            if ('{' === $c && 1 === preg_match('/\G\{,(\d+)\}/', $source, $quantifier, 0, $i)) {
                $out .= '{0,' . $quantifier[1] . '}';
                $i   += \strlen($quantifier[0]);

                continue;
            }

            $out .= $c;
            ++$i;
        }

        $named = array_filter($names, static fn (?string $name): bool => null !== $name);
        if (\count($named) !== \count(array_unique($named))) {
            $out = '(?J)' . $out;
        }

        return new TranslatedPattern($out, $names, $usesWord);
    }

    /**
     * `\p{name}` for an Oniguruma name PCRE does not know, or null to leave the escape to PCRE.
     *
     * @throws JqException when a negation cannot be expressed inside a character class
     */
    private static function translateProperty(string $source, string $name, bool $negated, bool $inClass, bool $ignoreCase): ?string
    {
        $key = str_replace([' ', '_', '-'], '', strtolower($name));
        if (!isset(self::PROPERTIES[$key])) {
            return null;
        }

        [$body, $negatedBody] = self::PROPERTIES[$key];
        if ($ignoreCase && ('upper' === $key || 'lower' === $key)) {
            [$body, $negatedBody] = [self::CASED, null];
        }

        if (!$negated) {
            return $inClass ? $body : '[' . $body . ']';
        }

        if (!$inClass) {
            return '[^' . $body . ']';
        }

        return $negatedBody ?? throw self::invalid($source, 'negated property inside a character class is not supported');
    }

    private static function translateEscape(string $escape, bool $inClass, bool $unicodeWord): string
    {
        switch ($escape) {
            case 'h':
            case 'H':
                return $escape;

            case 'w':
                if ($unicodeWord) {
                    return $inClass ? self::WORD : '[' . self::WORD . ']';
                }

                break;

            case 'W':
                if ($unicodeWord && !$inClass) {
                    return '[^' . self::WORD . ']';
                }

                break;

            case 'b':
                if ($unicodeWord && !$inClass) {
                    $word = '[' . self::WORD . ']';

                    return '(?:(?<=' . $word . ')(?!' . $word . ')|(?<!' . $word . ')(?=' . $word . '))';
                }

                break;

            case 'B':
                if ($unicodeWord && !$inClass) {
                    $word = '[' . self::WORD . ']';

                    return '(?:(?<=' . $word . ')(?=' . $word . ')|(?<!' . $word . ')(?!' . $word . '))';
                }

                break;

            default:
                break;
        }

        return '\\' . $escape;
    }

    /**
     * Copies the group opener at $i to $out, records capturing groups, and returns the index after it.
     *
     * @param list<?string> $names
     */
    private static function translateGroup(string $source, int $i, string &$out, array &$names, bool &$extended): int
    {
        $length = \strlen($source);
        $next   = $source[$i + 1] ?? '';

        if ('*' === $next) {
            $close = strpos($source, ')', $i);
            $stop  = false === $close ? $length : $close + 1;
            $out  .= substr($source, $i, $stop - $i);

            return $stop;
        }

        if ('?' !== $next) {
            $names[] = null;
            $out    .= '(';

            return $i + 1;
        }

        $kind = $source[$i + 2] ?? '';

        if ('#' === $kind) {
            $close = strpos($source, ')', $i);
            $stop  = false === $close ? $length : $close + 1;
            $out  .= str_replace('/', '\/', substr($source, $i, $stop - $i));

            return $stop;
        }

        if ('(' === $kind) {
            $close = strpos($source, ')', $i + 3);
            $stop  = false === $close ? $length : $close + 1;
            $out  .= substr($source, $i, $stop - $i);

            return $stop;
        }

        $named = self::namedGroupOpener($source, $i, $kind);
        if (null !== $named) {
            [$opener, $name, $stop] = $named;
            $names[]                = $name;
            $out                   .= $opener;

            return $stop;
        }

        if (1 === preg_match('/\G\(\?([a-zA-Z]*)(?:-([a-zA-Z]*))?[:)]/', $source, $flags, 0, $i)) {
            if (str_contains($flags[1], 'x')) {
                $extended = true;
            } elseif (str_contains($flags[2] ?? '', 'x')) {
                $extended = false;
            }
        }

        $out .= '(?';

        return $i + 2;
    }

    /**
     * @return ?array{string, string, int} the PCRE opener, the group name and the index after the opener
     */
    private static function namedGroupOpener(string $source, int $i, string $kind): ?array
    {
        $after = $source[$i + 3] ?? '';

        if ('<' === $kind && '=' !== $after && '!' !== $after) {
            return self::readName($source, $i + 3, '>', '(?<', '>');
        }

        if ("'" === $kind) {
            return self::readName($source, $i + 3, "'", "(?'", "'");
        }

        if ('P' === $kind && '<' === $after) {
            return self::readName($source, $i + 4, '>', '(?P<', '>');
        }

        return null;
    }

    /**
     * @return array{string, string, int}
     */
    private static function readName(string $source, int $nameStart, string $terminator, string $open, string $close): array
    {
        $end = strpos($source, $terminator, $nameStart);
        if (false === $end || $end === $nameStart) {
            throw self::invalid($source, 'group name is empty');
        }

        $name = substr($source, $nameStart, $end - $nameStart);

        return [$open . $name . $close, $name, $end + 1];
    }
}
