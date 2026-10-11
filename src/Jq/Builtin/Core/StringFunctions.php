<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\Values;

/**
 * String builtins: prefix and suffix tests and trims, split/join, case, explode/implode, and the
 * substring searches behind `indices`/`index`/`rindex`.
 *
 * @internal
 */
final readonly class StringFunctions
{
    private function __construct()
    {
    }

    public static function register(BuiltinRegistryInterface $registry): void
    {
        self::add($registry, 'startswith', 1, static function (RuntimeContextInterface $c, mixed $v, array $a): mixed {
            if (!\is_string($v) || !\is_string($a[0])) {
                throw new JqException('startswith() requires string inputs');
            }

            return str_starts_with($v, $a[0]);
        });
        self::add($registry, 'endswith', 1, static function (RuntimeContextInterface $c, mixed $v, array $a): mixed {
            if (!\is_string($v) || !\is_string($a[0])) {
                throw new JqException('endswith() requires string inputs');
            }

            return str_ends_with($v, $a[0]);
        });
        self::add($registry, 'ltrimstr', 1, static function (RuntimeContextInterface $c, mixed $v, array $a): mixed {
            if (!\is_string($v) || !\is_string($a[0])) {
                throw new JqException('startswith() requires string inputs');
            }

            return '' !== $a[0] && str_starts_with($v, $a[0]) ? substr($v, \strlen($a[0])) : $v;
        });
        self::add($registry, 'rtrimstr', 1, static function (RuntimeContextInterface $c, mixed $v, array $a): mixed {
            if (!\is_string($v) || !\is_string($a[0])) {
                throw new JqException('endswith() requires string inputs');
            }

            return '' !== $a[0] && str_ends_with($v, $a[0]) ? substr($v, 0, \strlen($v) - \strlen($a[0])) : $v;
        });
        self::add($registry, 'trim', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => Unicode::trim(self::trimInput($v), true, true));
        self::add($registry, 'ltrim', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => Unicode::trim(self::trimInput($v), true, false));
        self::add($registry, 'rtrim', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => Unicode::trim(self::trimInput($v), false, true));
        self::add($registry, 'ascii_downcase', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => \is_string($v)
            ? strtolower($v)
            : throw new JqException('ascii_downcase input must be a string'));
        self::add($registry, 'ascii_upcase', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => \is_string($v)
            ? strtoupper($v)
            : throw new JqException('ascii_upcase input must be a string'));
        self::add($registry, 'explode', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => \is_string($v)
            ? Unicode::codepoints($v)
            : throw new JqException('explode input must be a string'));
        self::add($registry, 'implode', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::implode($v));
        self::add($registry, 'split', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::split($v, $a[0]));
        self::add($registry, 'join', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::join($v, $a[0]));
        self::add($registry, '_strindices', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::stringIndices($v, $a[0]));
        self::add($registry, '_array_indices', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::arrayIndices($v, $a[0]));
    }

    private static function trimInput(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new JqException('trim input must be a string');
        }

        return $value;
    }

    private static function implode(mixed $value): string
    {
        if (!\is_array($value)) {
            throw new JqException('implode input must be an array');
        }

        $out = '';
        foreach ($value as $code) {
            if (!Num::isNumber($code) || is_nan(Num::toFloat($code))) {
                throw Problems::type($code, "can't be imploded, unicode codepoint needs to be numeric");
            }

            $out .= Unicode::encode(Num::toInt(Num::toFloat($code)));
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function split(mixed $text, mixed $separator): array
    {
        if (!\is_string($text) || !\is_string($separator)) {
            throw new JqException('split input and separator must be strings');
        }

        if ('' === $text) {
            return [];
        }

        if ('' === $separator) {
            return Unicode::characters($text);
        }

        return explode($separator, $text);
    }

    private static function join(mixed $items, mixed $separator): string
    {
        if ($items instanceof JsonObject) {
            $items = $items->values();
        }

        if (!\is_array($items)) {
            throw Problems::iterate($items);
        }

        $accumulator = null;
        foreach ($items as $item) {
            $left = '';
            if (null !== $accumulator) {
                $left = \is_string($accumulator) && \is_string($separator) ? $accumulator . $separator : Arithmetic::add($accumulator, $separator);
            }

            if (null === $item) {
                $item = '';
            } elseif (\is_bool($item) || Num::isNumber($item)) {
                $item = Problems::json($item);
            }

            $accumulator = \is_string($left) && \is_string($item) ? $left . $item : Arithmetic::add($left, $item);
        }

        if (null === $accumulator || false === $accumulator) {
            return '';
        }

        return \is_string($accumulator) ? $accumulator : Problems::json($accumulator);
    }

    /**
     * Codepoint offsets of every (overlapping) occurrence of $needle in $text.
     *
     * @return list<int>
     */
    private static function stringIndices(mixed $text, mixed $needle): array
    {
        if (!\is_string($text)) {
            throw Problems::type($text, 'cannot be searched, as it is not a string');
        }

        if (!\is_string($needle)) {
            throw Problems::type($needle, 'is not a string');
        }

        if ('' === $needle) {
            return [];
        }

        $out        = [];
        $offset     = 0;
        $ascii      = \strlen($text) === Unicode::length($text);
        $lastByte   = 0;
        $lastOffset = 0;
        while (false !== ($position = strpos($text, $needle, $offset))) {
            if (!$ascii) {
                $lastOffset += Unicode::length(substr($text, $lastByte, $position - $lastByte));
                $lastByte = $position;
            }

            $out[]  = $ascii ? $position : $lastOffset;
            $offset = $position + 1;
        }

        return $out;
    }

    /**
     * Start positions of every occurrence of the sub-array $needle in the array $haystack.
     *
     * @return list<int>
     */
    private static function arrayIndices(mixed $haystack, mixed $needle): array
    {
        if (!\is_array($haystack) || !\is_array($needle)) {
            throw Problems::type2($haystack, $needle, 'cannot be searched, as they are not both arrays');
        }

        $width = \count($needle);
        if (0 === $width) {
            return [];
        }

        $out  = [];
        $last = \count($haystack) - $width;
        for ($start = 0; $start <= $last; ++$start) {
            $matches = true;
            for ($k = 0; $k < $width; ++$k) {
                if (!Values::equals($haystack[$start + $k], $needle[$k])) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                $out[] = $start;
            }
        }

        return $out;
    }

    /**
     * @param Closure(RuntimeContextInterface, mixed, list<mixed>): mixed $function
     */
    private static function add(BuiltinRegistryInterface $registry, string $name, int $arity, Closure $function): void
    {
        $registry->register(new ValueFunction($name, $arity, $function));
    }
}
