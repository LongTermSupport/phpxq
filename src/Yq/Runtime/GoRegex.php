<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

/**
 * Go (RE2) regular expressions on top of PCRE: pattern translation, match records with rune offsets and
 * Go's replacement syntax (`${1}`, `$1`, `$name`, `$$`).
 */
final class GoRegex
{
    /** @var array<string, string> */
    private static array $compiled = [];

    private function __construct()
    {
    }

    /**
     * @param string $flags jq-style flags; `i`, `x`, `s` and `n`-less `m` map to PCRE modifiers, `g` is ignored here
     *
     * @throws EvaluationException
     */
    public static function compile(string $pattern, string $flags = ''): string
    {
        $key = $flags . "\0" . $pattern;
        if (isset(self::$compiled[$key])) {
            return self::$compiled[$key];
        }

        $modifiers = 'u';
        foreach (str_split($flags) as $flag) {
            if ('i' === $flag || 's' === $flag || 'x' === $flag) {
                $modifiers .= $flag;
            } elseif ('m' === $flag) {
                $modifiers .= 'm';
            }
        }

        $regex = '~' . self::escapeDelimiter($pattern) . '~' . $modifiers;
        set_error_handler(static fn (): bool => true);
        try {
            $ok = preg_match($regex, '');
        } finally {
            restore_error_handler();
        }

        if (false === $ok) {
            throw new EvaluationException(\sprintf('error parsing regexp: invalid or unsupported Perl syntax: `%s`', $pattern));
        }

        if (\count(self::$compiled) > 256) {
            self::$compiled = [];
        }

        self::$compiled[$key] = $regex;

        return $regex;
    }

    /**
     * @throws EvaluationException when the engine gives up (backtracking limit, malformed UTF-8 subject)
     */
    public static function test(string $regex, string $subject): bool
    {
        return 1 === self::checked(preg_match($regex, $subject));
    }

    /**
     * Match records: for each match the text, rune offset and length and its capture groups.
     *
     * @return list<array{string: string, offset: int, length: int, captures: list<array{string: ?string, offset: int, length: int, name: string}>}>
     *
     * @throws EvaluationException when the engine gives up (backtracking limit, malformed UTF-8 subject)
     */
    public static function matches(string $regex, string $subject, bool $global): array
    {
        $flags = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;
        $found = [];
        if ($global) {
            self::checked(preg_match_all($regex, $subject, $all, \PREG_SET_ORDER | $flags));
            $found = $all;
        } elseif (1 === self::checked(preg_match($regex, $subject, $one, $flags))) {
            $found = [$one];
        }

        $records = [];
        foreach ($found as $match) {
            $records[] = self::record($match, $subject);
        }

        return $records;
    }

    /**
     * Replaces every match (or the first when not `$global`) expanding Go's `$1`, `${1}`, `$name` and `$$`.
     *
     * @throws EvaluationException when the engine gives up (backtracking limit, malformed UTF-8 subject)
     */
    public static function replace(string $regex, string $replacement, string $subject, bool $global = true): string
    {
        $limit  = $global ? -1 : 1;
        $result = preg_replace_callback(
            $regex,
            static fn (array $m): string => self::expand($replacement, $m),
            $subject,
            $limit,
        );
        if (null === $result) {
            throw self::engineError();
        }

        return $result;
    }

    /**
     * The result of a PCRE match call, or the engine's own error as an evaluation error: `false` means the
     * engine gave up, not that nothing matched.
     *
     * @throws EvaluationException when the call failed
     */
    public static function checked(false|int $result): int
    {
        if (false === $result) {
            throw self::engineError();
        }

        return $result;
    }

    private static function engineError(): EvaluationException
    {
        return new EvaluationException(preg_last_error_msg());
    }

    /**
     * @param array<int|string, mixed> $match
     *
     * @return array{string: string, offset: int, length: int, captures: list<array{string: ?string, offset: int, length: int, name: string}>}
     */
    private static function record(array $match, string $subject): array
    {
        $whole = $match[0];
        $text  = \is_array($whole) && \is_string($whole[0]) ? $whole[0] : '';
        $byte  = \is_array($whole) && \is_int($whole[1]) ? $whole[1] : 0;

        $captures = [];
        $name     = '';
        foreach ($match as $key => $group) {
            if (\is_string($key)) {
                $name = $key;

                continue;
            }

            if (0 === $key) {
                continue;
            }

            if (!\is_array($group)) {
                $name = '';

                continue;
            }

            $value = $group[0];
            $at    = $group[1];
            if (!\is_int($at) || !\is_string($value) && null !== $value) {
                $name = '';

                continue;
            }

            if (null === $value) {
                $captures[] = ['string' => null, 'offset' => -1, 'length' => 0, 'name' => $name];
            } else {
                $captures[] = ['string' => $value, 'offset' => self::runes(substr($subject, 0, $at)), 'length' => self::runes($value), 'name' => $name];
            }

            $name = '';
        }

        return ['string' => $text, 'offset' => self::runes(substr($subject, 0, $byte)), 'length' => self::runes($text), 'captures' => $captures];
    }

    /**
     * Escapes each bare `~` for the `~` delimiter. An escape sequence is copied whole (`\~` is already a
     * literal tilde), and a tilde inside `\Q...\E` closes the quoted run around its escaped form.
     */
    private static function escapeDelimiter(string $pattern): string
    {
        $length = \strlen($pattern);
        $out    = '';
        $quoted = false;
        for ($i = 0; $i < $length; ++$i) {
            $c = $pattern[$i];
            if ($quoted) {
                if ('\\' === $c && 'E' === substr($pattern, $i + 1, 1)) {
                    $quoted = false;
                    $out .= '\E';
                    ++$i;
                } else {
                    $out .= '~' === $c ? '\E\~\Q' : $c;
                }

                continue;
            }

            if ('\\' === $c && $i + 1 < $length) {
                $quoted = 'Q' === $pattern[$i + 1];
                $out .= $c . $pattern[$i + 1];
                ++$i;

                continue;
            }

            $out .= '~' === $c ? '\~' : $c;
        }

        return $out;
    }

    private static function runes(string $text): int
    {
        if ('' === $text) {
            return 0;
        }

        return mb_strlen($text, 'UTF-8');
    }

    /**
     * @param array<int|string, string> $groups
     */
    private static function expand(string $template, array $groups): string
    {
        $resolve = static fn (string $name): string => self::group($groups, $name);

        return DollarTemplate::expand($template, $resolve, $resolve, false, true, true);
    }

    /**
     * @param array<int|string, string> $groups
     */
    private static function group(array $groups, string $name): string
    {
        if (ctype_digit($name)) {
            $index = (int)$name;

            return \array_key_exists($index, $groups) ? $groups[$index] : '';
        }

        return \array_key_exists($name, $groups) ? $groups[$name] : '';
    }
}
