<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

/**
 * The `envsubst` operator's text substitution: `$VAR`, `${VAR}` and the default forms
 * `${VAR-d}`, `${VAR:-d}`, `${VAR=d}`, `${VAR:=d}`, `${VAR+a}`, `${VAR:+a}`.
 */
final readonly class EnvSubst
{
    private function __construct()
    {
    }

    /**
     * @param bool $noUnset error on an unset variable (flag `nu`)
     * @param bool $noEmpty error on a set but empty variable (flag `ne`)
     *
     * @throws EvaluationException
     */
    public static function substitute(string $text, bool $noUnset = false, bool $noEmpty = false): string
    {
        $out = '';
        $n   = \strlen($text);
        $i   = 0;
        while ($i < $n) {
            $c = $text[$i];
            if ('$' !== $c) {
                $out .= $c;
                ++$i;

                continue;
            }

            $next = substr($text, $i + 1, 1);
            if ('{' === $next) {
                $end = self::closingBrace($text, $i + 2);
                if (null === $end) {
                    $out .= $c;
                    ++$i;

                    continue;
                }

                $out .= self::braced(substr($text, $i + 2, $end - $i - 2), $noUnset, $noEmpty);
                $i = $end + 1;

                continue;
            }

            if ('_' === $next || ctype_alpha($next)) {
                $j = $i + 1;
                while ($j < $n && (ctype_alnum($text[$j]) || '_' === $text[$j])) {
                    ++$j;
                }

                $name = substr($text, $i + 1, $j - $i - 1);
                $out .= self::value($name, $noUnset, $noEmpty);
                $i = $j;

                continue;
            }

            $out .= $c;
            ++$i;
        }

        return $out;
    }

    private static function closingBrace(string $text, int $from): ?int
    {
        $depth = 1;
        $n     = \strlen($text);
        for ($i = $from; $i < $n; ++$i) {
            if ('{' === $text[$i]) {
                ++$depth;
            } elseif ('}' === $text[$i] && 0 === --$depth) {
                return $i;
            }
        }

        return null;
    }

    private static function braced(string $body, bool $noUnset, bool $noEmpty): string
    {
        if (1 !== preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-=+])(.*))?$/s', $body, $m)) {
            return '';
        }

        // preg_match omits trailing groups that did not participate.
        $m += [2 => '', 3 => ''];

        $name  = $m[1];
        $op    = $m[2];
        $word  = $m[3];
        $raw   = getenv($name);
        $set   = false !== $raw;
        $value = false === $raw ? '' : $raw;
        if ('' === $op) {
            return self::value($name, $noUnset, $noEmpty);
        }

        $colon = ':' === $op[0];
        $kind  = $op[\strlen($op) - 1];
        $empty = $colon ? (!$set || '' === $value) : !$set;
        $word  = self::substitute($word, $noUnset, $noEmpty);

        return match ($kind) {
            '-'     => $empty ? $word : $value,
            '='     => $empty ? $word : $value,
            default => $empty ? '' : $word,
        };
    }

    private static function value(string $name, bool $noUnset, bool $noEmpty): string
    {
        $raw = getenv($name);
        if (false === $raw) {
            if ($noUnset) {
                throw new EvaluationException(\sprintf('variable ${%s} not set', $name));
            }

            return '';
        }

        if ($noEmpty && '' === $raw) {
            throw new EvaluationException(\sprintf('variable ${%s} set but empty', $name));
        }

        return $raw;
    }
}
