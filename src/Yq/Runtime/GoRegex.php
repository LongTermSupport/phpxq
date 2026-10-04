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

        $regex = '~' . str_replace('~', '\~', $pattern) . '~' . $modifiers;
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

    public static function test(string $regex, string $subject): bool
    {
        return 1 === preg_match($regex, $subject);
    }

    /**
     * Match records: for each match the text, rune offset and length and its capture groups.
     *
     * @return list<array{string: string, offset: int, length: int, captures: list<array{string: ?string, offset: int, length: int, name: string}>}>
     */
    public static function matches(string $regex, string $subject, bool $global): array
    {
        $flags = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;
        $found = [];
        if ($global) {
            if (false === preg_match_all($regex, $subject, $all, \PREG_SET_ORDER | $flags)) {
                return [];
            }

            $found = $all;
        } elseif (1 === preg_match($regex, $subject, $one, $flags)) {
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

        return $result ?? $subject;
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
        $out = '';
        $n   = \strlen($template);
        $i   = 0;
        while ($i < $n) {
            $c = $template[$i];
            if ('$' !== $c) {
                $out .= $c;
                ++$i;

                continue;
            }

            if ('$' === substr($template, $i + 1, 1)) {
                $out .= '$';
                $i += 2;

                continue;
            }

            if ('{' === substr($template, $i + 1, 1)) {
                $end = strpos($template, '}', $i + 2);
                if (false === $end) {
                    $out .= $c;
                    ++$i;

                    continue;
                }

                $name = substr($template, $i + 2, $end - $i - 2);
                $out .= self::group($groups, $name);
                $i = $end + 1;

                continue;
            }

            $j = $i + 1;
            while ($j < $n && (ctype_alnum($template[$j]) || '_' === $template[$j])) {
                ++$j;
            }

            if ($j === $i + 1) {
                $out .= $c;
                ++$i;

                continue;
            }

            $out .= self::group($groups, substr($template, $i + 1, $j - $i - 1));
            $i = $j;
        }

        return $out;
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
