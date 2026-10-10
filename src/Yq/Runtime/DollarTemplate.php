<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use Closure;

/**
 * The shared scanner behind `$name` / `${body}` text templates: literal text is copied, a `$name` is
 * resolved by one callback and a `${body}` by another. What counts as a template is a dialect choice.
 *
 * @internal
 */
final readonly class DollarTemplate
{
    private const string DOLLAR = '$';

    private const string OPEN = '{';

    private const string CLOSE = '}';

    private function __construct()
    {
    }

    /**
     * @param Closure(string): string $braced        resolves the text between `${` and `}`
     * @param Closure(string): string $bare          resolves the name after a `$`
     * @param bool                    $nestedBraces  a `${` closes at its balancing brace, not the first one
     * @param bool                    $dollarEscapes `$$` yields one literal `$`
     * @param bool                    $digitNames    a bare name may start with a digit
     */
    public static function expand(string $text, Closure $braced, Closure $bare, bool $nestedBraces, bool $dollarEscapes, bool $digitNames): string
    {
        $out = '';
        $n   = \strlen($text);
        $i   = 0;
        while ($i < $n) {
            $c = $text[$i];
            if (self::DOLLAR !== $c) {
                $out .= $c;
                ++$i;

                continue;
            }

            $next = substr($text, $i + 1, 1);
            if ($dollarEscapes && self::DOLLAR === $next) {
                $out .= self::DOLLAR;
                $i += 2;

                continue;
            }

            if (self::OPEN === $next) {
                $end = $nestedBraces ? self::balancedClose($text, $i + 2) : self::firstClose($text, $i + 2);
                if (null === $end) {
                    $out .= $c;
                    ++$i;

                    continue;
                }

                $out .= $braced(substr($text, $i + 2, $end - $i - 2));
                $i = $end + 1;

                continue;
            }

            if (!self::startsName($next, $digitNames)) {
                $out .= $c;
                ++$i;

                continue;
            }

            $j = $i + 1;
            while ($j < $n && (ctype_alnum($text[$j]) || '_' === $text[$j])) {
                ++$j;
            }

            $out .= $bare(substr($text, $i + 1, $j - $i - 1));
            $i = $j;
        }

        return $out;
    }

    private static function startsName(string $char, bool $digitNames): bool
    {
        return '_' === $char || ctype_alpha($char) || ($digitNames && ctype_digit($char));
    }

    private static function firstClose(string $text, int $from): ?int
    {
        $end = strpos($text, self::CLOSE, $from);

        return false === $end ? null : $end;
    }

    private static function balancedClose(string $text, int $from): ?int
    {
        $depth = 1;
        $n     = \strlen($text);
        for ($i = $from; $i < $n; ++$i) {
            if (self::OPEN === $text[$i]) {
                ++$depth;
            } elseif (self::CLOSE === $text[$i] && 0 === --$depth) {
                return $i;
            }
        }

        return null;
    }
}
