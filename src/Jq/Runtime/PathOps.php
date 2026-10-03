<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LogicException;

/**
 * getpath / setpath / delpaths on the value model: the single implementation used by the evaluator
 * (assignment operators, `del`, `to_entries`-style builtins) and by the natives of the same names.
 * OWNER: evaluator-core worker. Skeleton only: keep these signatures when implementing.
 *
 * A path element is a string (object key), an int (array index, negative counts from the end), a slice
 * object {"start": n|null, "end": n|null}, or null (only valid as a getpath step through null).
 *
 * @api
 */
final class PathOps
{
    private function __construct()
    {
    }

    /**
     * @param list<mixed> $path
     */
    public static function getPath(mixed $value, array $path): mixed
    {
        throw self::notImplemented('getPath', $value, $path);
    }

    /**
     * @param list<mixed> $path
     */
    public static function setPath(mixed $value, array $path, mixed $new): mixed
    {
        throw self::notImplemented('setPath', $value, $path, $new);
    }

    /**
     * Delete every path (sorted and removed from the last to the first so indices stay valid).
     *
     * @param list<list<mixed>> $paths
     */
    public static function deletePaths(mixed $value, array $paths): mixed
    {
        throw self::notImplemented('deletePaths', $value, $paths);
    }

    private static function notImplemented(string $operation, mixed ...$arguments): LogicException
    {
        return new LogicException(\sprintf('PathOps::%s is not implemented (%d arguments)', $operation, \count($arguments)));
    }
}
