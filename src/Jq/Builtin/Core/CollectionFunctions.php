<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Json\Values;

/**
 * Array and object builtins: sorting and grouping (natively, the `_by` forms receive the keys computed by
 * the prelude), min/max, unique, reverse, flatten, add, transpose, bsearch, to_entries and from_entries.
 *
 * @internal
 */
final class CollectionFunctions
{
    private const int TWO_TO_53 = 9007199254740992;

    private function __construct()
    {
    }

    public static function register(BuiltinRegistryInterface $registry): void
    {
        self::add($registry, 'sort', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::sort($v));
        self::add($registry, 'unique', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::unique($v));
        self::add($registry, 'min', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::extreme($v, $v, true));
        self::add($registry, 'max', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::extreme($v, $v, false));
        self::add($registry, '_sort_by_impl', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::sortBy($v, $a[0]));
        self::add($registry, '_group_by_impl', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::groupBy($v, $a[0], false));
        self::add($registry, '_unique_by_impl', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::groupBy($v, $a[0], true));
        self::add($registry, '_min_by_impl', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::extreme($v, $a[0], true));
        self::add($registry, '_max_by_impl', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::extreme($v, $a[0], false));
        self::add($registry, 'reverse', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::reverse($v));
        self::add($registry, 'flatten', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::flatten($v, \PHP_INT_MAX));
        self::add($registry, 'flatten', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::flatten($v, self::depth($a[0])));
        self::add($registry, 'add', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::add0($v));
        self::add($registry, 'transpose', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::transpose($v));
        self::add($registry, 'bsearch', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => self::bsearch($v, $a[0]));
        self::add($registry, 'to_entries', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::toEntries($v));
        self::add($registry, 'from_entries', 0, static fn (RuntimeContextInterface $c, mixed $v): mixed => self::fromEntries($v));
    }

    /**
     * Sorted copy of a list by jq's ordering (stable). Lists of only strings or only plain numbers take PHP's
     * native sort.
     *
     * @param array<array-key, mixed> $list
     *
     * @return list<mixed>
     */
    public static function sortList(array $list): array
    {
        $items = array_values($list);
        $count = \count($items);
        if ($count < 2) {
            return $items;
        }

        $strings = [];
        $numbers = [];
        foreach ($items as $item) {
            if (\is_string($item)) {
                $strings[] = $item;
            } elseif (\is_int($item) || (\is_float($item) && !is_nan($item))) {
                $numbers[] = $item;
            } else {
                break;
            }

            if ([] !== $strings && [] !== $numbers) {
                break;
            }
        }

        if (\count($strings) === $count) {
            sort($strings, \SORT_STRING);

            return $strings;
        }

        if (\count($numbers) === $count) {
            sort($numbers, \SORT_NUMERIC);

            return $numbers;
        }

        foreach ($items as $item) {
            if (Nesting::tooDeep($item)) {
                throw new JqException('Comparison too deep');
            }
        }

        usort($items, Values::compare(...));

        return $items;
    }

    /**
     * @return list<mixed>
     */
    private static function sort(mixed $value): array
    {
        if (!\is_array($value)) {
            throw Problems::type($value, 'cannot be sorted, as it is not an array');
        }

        return self::sortList($value);
    }

    /**
     * @return list<mixed>
     */
    private static function unique(mixed $value): array
    {
        if (!\is_array($value)) {
            throw Problems::type($value, 'cannot be sorted, as it is not an array');
        }

        $sorted = self::sortList($value);
        $out    = [];
        foreach ($sorted as $item) {
            if ([] === $out || !Values::equals($out[\count($out) - 1], $item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Indexes of $values ordered by their keys (stable).
     *
     * @param array<array-key, mixed> $keys
     *
     * @return list<array-key>
     */
    private static function orderByKeys(array $keys): array
    {
        foreach ($keys as $key) {
            if (Nesting::tooDeep($key)) {
                throw new JqException('Comparison too deep');
            }
        }

        $order = array_keys($keys);
        usort($order, static fn (int|string $left, int|string $right): int => Values::compare($keys[$left], $keys[$right]));

        return $order;
    }

    /**
     * @return list<mixed>
     */
    private static function sortBy(mixed $values, mixed $keys): array
    {
        if (!\is_array($values) || !\is_array($keys) || \count($values) !== \count($keys)) {
            throw Problems::type2($values, $keys, 'cannot be sorted, as they are not both arrays');
        }

        $out = [];
        foreach (self::orderByKeys($keys) as $index) {
            $out[] = $values[$index];
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private static function groupBy(mixed $values, mixed $keys, bool $firstOnly): array
    {
        if (!\is_array($values) || !\is_array($keys) || \count($values) !== \count($keys)) {
            throw Problems::type2($values, $keys, 'cannot be grouped, as they are not both arrays');
        }

        $groups   = [];
        $previous = null;
        $current  = -1;
        foreach (self::orderByKeys($keys) as $position => $index) {
            if (0 === $position || 0 !== Values::compare($previous, $keys[$index])) {
                ++$current;
                $groups[$current] = [];
                $previous         = $keys[$index];
            }

            $groups[$current][] = $values[$index];
        }

        if ($firstOnly) {
            return array_values(array_map(static fn (array $group): mixed => $group[0], $groups));
        }

        return array_values($groups);
    }

    /**
     * min / max / min_by / max_by: the first minimum, the last maximum.
     */
    private static function extreme(mixed $values, mixed $keys, bool $minimum): mixed
    {
        if (!\is_array($values) || !\is_array($keys)) {
            throw Problems::type2($values, $keys, 'cannot be iterated over');
        }

        if (\count($values) !== \count($keys)) {
            throw Problems::type2($values, $keys, 'have wrong length');
        }

        if ([] === $values) {
            return null;
        }

        $best = 0;
        $last = \count($values);
        for ($i = 1; $i < $last; ++$i) {
            $order = Values::compare($keys[$i], $keys[$best]);
            if ($minimum ? $order < 0 : $order >= 0) {
                $best = $i;
            }
        }

        return $values[$best];
    }

    private static function reverse(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_reverse($value);
        }

        if (\is_string($value)) {
            return implode('', array_reverse(Unicode::characters($value)));
        }

        if (null === $value) {
            return [];
        }

        $length = TypeFunctions::length($value);
        if ($value instanceof JsonObject && 0 === \count($value)) {
            return [];
        }

        throw Problems::index($value, Num::of(Num::toFloat($length) - 1));
    }

    private static function depth(mixed $depth): int
    {
        if (!Num::isNumber($depth)) {
            throw new JqException('flatten depth must not be negative');
        }

        $number = Num::toFloat($depth);
        if ($number < 0) {
            throw new JqException('flatten depth must not be negative');
        }

        return Num::toInt($number);
    }

    /**
     * @return list<mixed>
     */
    private static function flatten(mixed $value, int $depth): array
    {
        if ($value instanceof JsonObject) {
            $value = $value->values();
        }

        if (!\is_array($value)) {
            throw Problems::iterate($value);
        }

        $out = [];
        self::flattenInto($value, $depth, $out);

        return $out;
    }

    /**
     * @param array<array-key, mixed> $items
     * @param list<mixed>             $out
     */
    private static function flattenInto(array $items, int $depth, array &$out): void
    {
        foreach ($items as $item) {
            if (\is_array($item) && 0 !== $depth) {
                self::flattenInto($item, $depth - 1, $out);

                continue;
            }

            $out[] = $item;
        }
    }

    private static function add0(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            $value = $value->values();
        }

        if (!\is_array($value)) {
            throw Problems::iterate($value);
        }

        if ([] === $value) {
            return null;
        }

        $fast = self::addFast($value);
        if (null !== $fast) {
            return $fast[0];
        }

        $sum = null;
        foreach ($value as $item) {
            $sum = Arithmetic::add($sum, $item);
        }

        return $sum;
    }

    /**
     * Sum of a list whose elements all have the same simple kind (strings, lists, objects, plain numbers),
     * null elements allowed; null when the generic path has to decide. Wrapped in a list so that a null
     * sum can be told from "not applicable".
     *
     * @param array<array-key, mixed> $items
     *
     * @return ?array{mixed}
     */
    private static function addFast(array $items): ?array
    {
        $kind = null;
        foreach ($items as $item) {
            if (null === $item) {
                continue;
            }

            $itemKind = match (true) {
                \is_string($item)                => 's',
                \is_array($item)                 => 'a',
                $item instanceof JsonObject      => 'o',
                \is_int($item), \is_float($item) => 'n',
                default                          => 'x',
            };
            if ('x' === $itemKind || (null !== $kind && $kind !== $itemKind)) {
                return null;
            }

            $kind = $itemKind;
        }

        return match ($kind) {
            null    => [null],
            's'     => [implode('', array_filter($items, \is_string(...)))],
            'a'     => [array_merge(...array_values(array_filter($items, \is_array(...))))],
            'o'     => [self::mergeObjects($items)],
            default => [self::sumNumbers($items)],
        };
    }

    /**
     * @param array<array-key, mixed> $items
     */
    private static function mergeObjects(array $items): JsonObject
    {
        $members = [];
        foreach ($items as $item) {
            if ($item instanceof JsonObject) {
                $members = array_replace($members, $item->toArray());
            }
        }

        return new JsonObject($members);
    }

    /**
     * @param array<array-key, mixed> $items
     */
    private static function sumNumbers(array $items): int|float
    {
        $sum = 0;
        foreach ($items as $item) {
            if (\is_int($item) || \is_float($item)) {
                $sum += $item;
                if (\is_int($sum) && abs($sum) > self::TWO_TO_53) {
                    $sum = (float)$sum;
                }
            }
        }

        return Num::of($sum);
    }

    /**
     * @return list<list<mixed>>
     */
    private static function transpose(mixed $value): array
    {
        if (!\is_array($value)) {
            throw Problems::iterate($value);
        }

        $width = 0;
        foreach ($value as $row) {
            $width = max($width, Num::toInt(Num::toFloat(self::rowLength($row))));
        }

        $out = [];
        for ($j = 0; $j < $width; ++$j) {
            $column = [];
            foreach ($value as $row) {
                $column[] = \is_array($row) ? ($row[$j] ?? null) : null;
            }

            $out[] = $column;
        }

        return $out;
    }

    private static function rowLength(mixed $row): int|float|PreciseNumber
    {
        if (null === $row || \is_array($row)) {
            return TypeFunctions::length($row);
        }

        throw Problems::index($row, 0);
    }

    private static function bsearch(mixed $haystack, mixed $target): int
    {
        if (!\is_array($haystack)) {
            throw Problems::type($haystack, 'cannot be searched from');
        }

        $low  = 0;
        $high = \count($haystack) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $order  = Values::compare($haystack[$middle], $target);
            if (0 === $order) {
                return $middle;
            }

            if ($order < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return -1 - $low;
    }

    /**
     * @return list<JsonObject>
     */
    private static function toEntries(mixed $value): array
    {
        if ($value instanceof JsonObject) {
            $out = [];
            foreach ($value->toArray() as $key => $member) {
                $out[] = new JsonObject(['key' => (string)$key, 'value' => $member]);
            }

            return $out;
        }

        if (\is_array($value)) {
            $out = [];
            foreach ($value as $index => $member) {
                $out[] = new JsonObject(['key' => $index, 'value' => $member]);
            }

            return $out;
        }

        throw Problems::type($value, 'has no keys');
    }

    private static function fromEntries(mixed $value): JsonObject
    {
        if ($value instanceof JsonObject) {
            $value = $value->values();
        }

        if (!\is_array($value)) {
            throw Problems::iterate($value);
        }

        $members = [];
        foreach ($value as $entry) {
            if (!$entry instanceof JsonObject) {
                if (null === $entry) {
                    throw new JqException('Cannot check whether null has a string key');
                }

                throw Problems::index($entry, 'key');
            }

            $key = $entry->get('key');
            $key ??= self::firstTruthy($entry, ['k', 'name', 'Name', 'K', 'Key']);

            $name = \is_string($key) ? $key : Problems::json($key);
            if ($entry->has('value')) {
                $members[$name] = $entry->get('value');
            } elseif ($entry->has('v')) {
                $members[$name] = $entry->get('v');
            } else {
                $members[$name] = $entry->get('Value');
            }
        }

        return new JsonObject($members);
    }

    /**
     * jq's `a // b // c`: the first truthy field, else the last field whatever it holds.
     *
     * @param list<string> $fields
     */
    private static function firstTruthy(JsonObject $object, array $fields): mixed
    {
        $value = null;
        foreach ($fields as $field) {
            $value = $object->get($field);
            if (Values::isTruthy($value)) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * @param Closure(RuntimeContextInterface, mixed, list<mixed>): mixed $function
     */
    private static function add(BuiltinRegistryInterface $registry, string $name, int $arity, Closure $function): void
    {
        $registry->register(new ValueFunction($name, $arity, $function));
    }
}
