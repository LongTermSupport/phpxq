<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * Translates an Oniguruma pattern (jq compiles with ONIG_SYNTAX_PERL_NT, so it is already close to PCRE)
 * into PCRE source for the `/` delimiter.
 *
 * Translations: `\h`/`\H` are hex digits in Oniguruma (horizontal space in PCRE); `{,n}` means `{0,n}`;
 * the delimiter `/` is escaped; duplicate group names are allowed. For subjects with non-ASCII text,
 * `\w \W \b \B` are rewritten to Oniguruma's Unicode word definition (letters, marks, decimal and letter
 * numbers, connector punctuation), because PCRE2 before 10.43 leaves combining marks out of `\w`.
 *
 * Known limits: `\H` and `\W` cannot be rewritten inside a character class (`\H` is rejected, `\W` keeps
 * PCRE's meaning); Oniguruma-only constructs (absent operator `(?~...)`, `\p{...}` property names PCRE
 * lacks, `\y`/`\Y`) are left to PCRE and fail as invalid regexes.
 *
 * @internal
 */
final class RegexTranslator
{
    private const string HEX = '0-9a-fA-F';

    private const string WORD = '\p{L}\p{M}\p{Nd}\p{Nl}\p{Pc}';

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

    public static function translate(string $source, bool $extended, bool $unicodeWord): TranslatedPattern
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

                $out .= self::translateEscape($source, $escape, $inClass, $unicodeWord);
                $i   += 2;

                continue;
            }

            if ($inClass) {
                if ('[' === $c && ':' === ($source[$i + 1] ?? '')) {
                    $close = strpos($source, ':]', $i + 2);
                    if (false !== $close) {
                        $out .= substr($source, $i, $close + 2 - $i);
                        $i    = $close + 2;

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

    private static function translateEscape(string $source, string $escape, bool $inClass, bool $unicodeWord): string
    {
        switch ($escape) {
            case 'h':
                return $inClass ? self::HEX : '[' . self::HEX . ']';

            case 'H':
                if ($inClass) {
                    throw self::invalid($source, '\H inside a character class is not supported');
                }

                return '[^' . self::HEX . ']';

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
            return self::readName($source, $i, $i + 3, '>', '(?<', '>');
        }

        if ("'" === $kind) {
            return self::readName($source, $i, $i + 3, "'", "(?'", "'");
        }

        if ('P' === $kind && '<' === $after) {
            return self::readName($source, $i, $i + 4, '>', '(?P<', '>');
        }

        return null;
    }

    /**
     * @return array{string, string, int}
     */
    private static function readName(string $source, int $groupStart, int $nameStart, string $terminator, string $open, string $close): array
    {
        $end = strpos($source, $terminator, $nameStart);
        if (false === $end || $end === $nameStart) {
            throw self::invalid($source, 'group name is empty');
        }

        $name = substr($source, $nameStart, $end - $nameStart);

        return [$open . $name . $close, $name, $end + 1];
    }
}
