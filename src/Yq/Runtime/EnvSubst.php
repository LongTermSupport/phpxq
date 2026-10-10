<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

/**
 * The `envsubst` operator's text substitution: `$VAR`, `${VAR}` and the default forms
 * `${VAR-d}`, `${VAR:-d}`, `${VAR=d}`, `${VAR:=d}`, `${VAR+a}`, `${VAR:+a}`.
 *
 * @internal
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
        return DollarTemplate::expand(
            $text,
            static fn (string $body): string => self::braced($body, $noUnset, $noEmpty),
            static fn (string $name): string => self::value($name, $noUnset, $noEmpty),
            true,
            false,
            false,
        );
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
