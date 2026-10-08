<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\Eval\Access;
use LTS\PhpXq\Jq\Runtime\Eval\ErrorText;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Json\Values;
use LTS\PhpXq\Limits\AllocationLimit;

/**
 * getpath / setpath / delpaths on the value model: the single implementation used by the evaluator
 * (assignment operators, `del`, `to_entries`-style builtins) and by the natives of the same names.
 *
 * A path element is a string (object key), an int (array index, negative counts from the end), a slice
 * object {"start": n|null, "end": n|null}, or null (only valid as a getpath step through null).
 *
 * INDEXED_AS names what a key's type is called in the error for deleting at that key of an array: a string key
 * would have addressed an object, a number key an array.
 *
 * @api
 */
final readonly class PathOps
{
    private const array INDEXED_AS = [
        'string' => 'object',
        'number' => 'array',
    ];

    public const string INDEX_TOO_LARGE = 'Array index too large';

    public const string PADDING_TOO_FAR = 'Cannot pad array to index %d: more than %d nulls would be added';

    public const string PADDING_OUT_OF_MEMORY = 'Cannot pad array to index %d: %d nulls would not fit in the memory_limit';

    private const int MAX_PATH_DEPTH = 10000;

    private function __construct()
    {
    }

    /**
     * Refuses an index past jq's own limit, and one so far past the end of an array of $count elements that
     * padding up to it would exceed {@see AllocationLimit::MAX_PADDING} or what the memory_limit leaves.
     *
     * @throws JqException
     */
    public static function checkPadding(int $index, int $count): void
    {
        if ($index > AllocationLimit::MAX_ARRAY_INDEX) {
            throw new JqException(self::INDEX_TOO_LARGE);
        }

        if (AllocationLimit::padsTooFar($index, $count)) {
            throw new JqException(\sprintf(self::PADDING_TOO_FAR, $index, AllocationLimit::MAX_PADDING));
        }

        if (AllocationLimit::exceedsMemoryLimit($index - $count, AllocationLimit::JQ_PADDED_ENTRY_BYTES)) {
            throw new JqException(\sprintf(self::PADDING_OUT_OF_MEMORY, $index, $index - $count));
        }
    }

    /**
     * @throws JqException
     */
    public static function getPath(mixed $value, mixed ...$path): mixed
    {
        self::assertShallow(\count($path));

        foreach ($path as $key) {
            if (null === $value) {
                return null;
            }

            $value = Access::index($value, $key);
        }

        return $value;
    }

    /**
     * @throws JqException
     */
    public static function setPath(mixed $value, mixed $new, mixed ...$path): mixed
    {
        $path = array_values($path);
        self::assertShallow(\count($path));

        $levels = [$value];
        foreach ($path as $key) {
            $value    = Access::index($value, $key);
            $levels[] = $value;
        }

        $result = $new;
        for ($i = \count($path) - 1; $i >= 0; --$i) {
            $result = self::setKey($levels[$i], $path[$i], $result);
        }

        return $result;
    }

    /**
     * Delete every path (sorted and removed from the last to the first so indices stay valid).
     *
     * @param mixed ...$paths each element must itself be a path (a list)
     *
     * @throws JqException
     */
    public static function deletePaths(mixed $value, mixed ...$paths): mixed
    {
        $sorted = [];
        foreach ($paths as $path) {
            if (!\is_array($path) || !array_is_list($path)) {
                throw new JqException('Path must be specified as an array');
            }

            self::assertShallow(\count($path));
            $sorted[] = $path;
        }

        if ([] === $sorted) {
            return $value;
        }

        usort($sorted, Values::compare(...));
        if ([] === $sorted[0]) {
            return null;
        }

        return self::deleteSorted($value, 0, ...$sorted);
    }

    /**
     * `.[$key] = $new` on one level: the shared building block of setPath.
     *
     * @throws JqException
     */
    public static function setKey(mixed $target, mixed $key, mixed $new): mixed
    {
        if (\is_string($key)) {
            if ($target instanceof JsonObject) {
                return $target->with($key, $new);
            }

            if (null === $target) {
                return new JsonObject([$key => $new]);
            }
        } elseif (\is_int($key) || \is_float($key) || $key instanceof PreciseNumber) {
            if (null === $target || \is_array($target)) {
                return self::setIndex($target ?? [], $key, $new);
            }
        } elseif ($key instanceof JsonObject) {
            if (null === $target || \is_array($target)) {
                return self::setSlice($target ?? [], $key, $new);
            }

            if (\is_string($target)) {
                throw new JqException('Cannot update string slices');
            }
        } elseif (\is_array($key) && \is_array($target)) {
            throw new JqException('Cannot update field at array index of array');
        }

        throw ErrorText::indexError($target, $key);
    }

    /**
     * @throws JqException when the path has more than 10000 steps
     */
    private static function assertShallow(int $steps): void
    {
        if ($steps > self::MAX_PATH_DEPTH) {
            throw new JqException('Path too deep');
        }
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return list<mixed>
     *
     * @throws JqException
     */
    private static function setIndex(array $array, int|float|PreciseNumber $key, mixed $new): array
    {
        $index = \is_int($key) ? $key : Access::position($key);
        if (null === $index) {
            throw new JqException('Cannot set array element at NaN index');
        }

        $count = \count($array);
        if ($index < 0) {
            $index += $count;
            if ($index < 0) {
                throw new JqException('Out of bounds negative array index');
            }
        }

        self::checkPadding($index, $count);

        for ($i = $count; $i < $index; ++$i) {
            $array[] = null;
        }

        $array[$index] = $new;

        return array_values($array);
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return list<mixed>
     *
     * @throws JqException
     */
    private static function setSlice(array $array, JsonObject $slice, mixed $new): array
    {
        if (!\is_array($new)) {
            throw new JqException('A slice of an array can only be assigned another array');
        }

        [$start, $end] = Access::bounds(\count($array), $slice->get('start'), $slice->get('end'));

        return array_values([...\array_slice($array, 0, $start), ...$new, ...\array_slice($array, $end)]);
    }

    /**
     * @param list<mixed> ...$paths sorted
     *
     * @throws JqException
     */
    private static function deleteSorted(mixed $value, int $start, array ...$paths): mixed
    {
        $paths = array_values($paths);
        $keys  = [];
        $total = \count($paths);
        $i     = 0;
        while ($i < $total) {
            $path = $paths[$i];
            $key  = $path[$start];
            $j    = $i + 1;
            while ($j < $total && self::sameKey($key, $paths[$j][$start])) {
                ++$j;
            }

            if (\count($path) === $start + 1) {
                $keys[] = $key;
            } else {
                $sub = Access::index($value, $key);
                if (null !== $sub) {
                    $value = self::setKey($value, $key, self::deleteSorted($sub, $start + 1, ...\array_slice($paths, $i, $j - $i)));
                }
            }

            $i = $j;
        }

        return self::deleteKeys($value, ...$keys);
    }

    /**
     * @throws JqException
     */
    private static function deleteKeys(mixed $value, mixed ...$keys): mixed
    {
        if ([] === $keys || null === $value) {
            return $value;
        }

        if ($value instanceof JsonObject) {
            foreach ($keys as $key) {
                if (!\is_string($key)) {
                    throw new JqException(\sprintf('Cannot delete field at %s index of object', self::keyKind($key)));
                }

                $value = $value->without($key);
            }

            return $value;
        }

        if (\is_array($value)) {
            return self::deleteIndices($value, ...$keys);
        }

        throw new JqException(\sprintf('Cannot delete fields from %s', Values::typeName($value)));
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return list<mixed>
     *
     * @throws JqException
     */
    private static function deleteIndices(array $array, mixed ...$keys): array
    {
        $count   = \count($array);
        $deleted = [];
        foreach ($keys as $key) {
            if (\is_int($key) || \is_float($key) || $key instanceof PreciseNumber) {
                $index = \is_int($key) ? $key : Access::position($key);
                if (null === $index) {
                    continue;
                }

                if ($index < 0) {
                    $index += $count;
                }

                if ($index >= 0 && $index < $count) {
                    $deleted[$index] = true;
                }
            } elseif ($key instanceof JsonObject) {
                [$start, $end] = Access::bounds($count, $key->get('start'), $key->get('end'));
                for ($i = $start; $i < $end; ++$i) {
                    $deleted[$i] = true;
                }
            } else {
                throw new JqException(\sprintf('Cannot delete field at %s index of array', self::keyKind($key)));
            }
        }

        $kept = [];
        foreach ($array as $index => $element) {
            if (!isset($deleted[$index])) {
                $kept[] = $element;
            }
        }

        return $kept;
    }

    /**
     * Whether two path keys group together: equal values, with nan keys equal to each other.
     */
    private static function sameKey(mixed $left, mixed $right): bool
    {
        if (\is_float($left) && is_nan($left) && \is_float($right) && is_nan($right)) {
            return true;
        }

        return Values::equals($left, $right);
    }

    private static function keyKind(mixed $key): string
    {
        $kind = Values::typeName($key);

        return self::INDEXED_AS[$kind] ?? $kind;
    }
}
