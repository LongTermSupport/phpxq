<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\PathOps;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonObject;

/**
 * Path builtins: path, getpath, setpath, delpaths, paths and the streaming forms tostream and fromstream.
 * The primitives are {@see PathOps}, shared with the evaluator's assignment operators.
 *
 * @internal
 */
final class PathFunctions
{
    private const int MAX_PATH_DEPTH = 10000;

    private function __construct()
    {
    }

    public static function register(BuiltinRegistryInterface $registry): void
    {
        $registry->register(new PathStreamFunction('path', 1, self::path(...), self::pathOfPath(...)));
        $registry->register(new PathStreamFunction('getpath', 1, self::getPath(...), self::getPathPaths(...)));
        $registry->register(new ValueFunction('setpath', 2, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => PathOps::setPath($v, self::pathArgument($a[0]), $a[1])));
        $registry->register(new ValueFunction('delpaths', 1, static fn (RuntimeContextInterface $c, mixed $v, array $a): mixed => PathOps::deletePaths($v, self::pathList($a[0]))));
        $registry->register(new StreamFunction('paths', 0, self::paths(...)));
        $registry->register(new StreamFunction('tostream', 0, self::toStream(...)));
        $registry->register(new StreamFunction('fromstream', 1, self::fromStream(...)));
    }

    /**
     * @return list<mixed>
     */
    private static function pathArgument(mixed $path): array
    {
        if (!\is_array($path) || !array_is_list($path)) {
            throw new JqException('Path must be specified as an array');
        }

        if (\count($path) > self::MAX_PATH_DEPTH) {
            throw new JqException('Path too deep');
        }

        return $path;
    }

    /**
     * @return list<list<mixed>>
     */
    private static function pathList(mixed $paths): array
    {
        if (!\is_array($paths)) {
            throw new JqException('Paths must be specified as an array');
        }

        $out = [];
        foreach ($paths as $path) {
            $out[] = self::pathArgument($path);
        }

        return $out;
    }

    /**
     * @param list<FilterInterface> $args
     * @param Closure(mixed): void  $emit
     */
    private static function path(RuntimeContextInterface $c, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->paths([], $input, static function (?array $path, mixed $value) use ($emit): void {
            if (null === $path) {
                throw new JqException('Invalid path expression with result ' . Problems::dump($value));
            }

            $emit($path);
        });
    }

    /**
     * @param ?list<mixed>                       $path
     * @param list<FilterInterface>              $args
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function pathOfPath(RuntimeContextInterface $c, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        self::path($c, $input, $args, static function (mixed $found) use ($emit): void {
            $emit(null, $found);
        });
    }

    /**
     * @param list<FilterInterface> $args
     * @param Closure(mixed): void  $emit
     */
    private static function getPath(RuntimeContextInterface $c, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $path) use ($input, $emit): void {
            $emit(PathOps::getPath($input, self::pathArgument($path)));
        });
    }

    /**
     * @param ?list<mixed>                       $path
     * @param list<FilterInterface>              $args
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function getPathPaths(RuntimeContextInterface $c, ?array $path, mixed $input, array $args, Closure $emit): void
    {
        $args[0]->run($input, static function (mixed $extra) use ($path, $input, $emit): void {
            $steps = self::pathArgument($extra);
            $emit(null === $path ? null : [...$path, ...$steps], PathOps::getPath($input, $steps));
        });
    }

    /**
     * Every path below the root, in document order.
     *
     * @param list<FilterInterface> $args
     * @param Closure(mixed): void  $emit
     */
    private static function paths(RuntimeContextInterface $c, mixed $input, array $args, Closure $emit): void
    {
        self::walkPaths([], $input, $emit);
    }

    /**
     * @param list<mixed>          $prefix
     * @param Closure(mixed): void $emit
     */
    private static function walkPaths(array $prefix, mixed $value, Closure $emit): void
    {
        if (\is_array($value)) {
            foreach ($value as $index => $child) {
                $path = [...$prefix, $index];
                $emit($path);
                self::walkPaths($path, $child, $emit);
            }
        } elseif ($value instanceof JsonObject) {
            foreach ($value->toArray() as $key => $child) {
                $path = [...$prefix, (string)$key];
                $emit($path);
                self::walkPaths($path, $child, $emit);
            }
        }
    }

    /**
     * @param list<FilterInterface> $args
     * @param Closure(mixed): void  $emit
     */
    private static function toStream(RuntimeContextInterface $c, mixed $input, array $args, Closure $emit): void
    {
        self::streamEvents([], $input, $emit);
    }

    /**
     * @param list<mixed>          $path
     * @param Closure(mixed): void $emit
     */
    private static function streamEvents(array $path, mixed $value, Closure $emit): void
    {
        $children = null;
        if (\is_array($value) && [] !== $value) {
            $children = $value;
        } elseif ($value instanceof JsonObject && \count($value) > 0) {
            $children = $value->toArray();
        }

        if (null === $children) {
            $emit([$path, $value]);

            return;
        }

        $last = null;
        foreach ($children as $key => $child) {
            $last = \is_int($key) && \is_array($value) ? $key : (string)$key;
            self::streamEvents([...$path, $last], $child, $emit);
        }

        $emit([[...$path, $last]]);
    }

    /**
     * @param list<FilterInterface> $args
     * @param Closure(mixed): void  $emit
     */
    private static function fromStream(RuntimeContextInterface $c, mixed $input, array $args, Closure $emit): void
    {
        $value = null;
        $done  = false;
        $args[0]->run($input, static function (mixed $event) use (&$value, &$done, $emit): void {
            if ($done) {
                $value = null;
                $done  = false;
            }

            if (!\is_array($event) || !isset($event[0])) {
                throw new JqException('Invalid stream event');
            }

            $path = self::pathArgument($event[0]);
            if (2 === \count($event)) {
                $done  = [] === $path;
                $value = PathOps::setPath($value, $path, $event[1]);
            } else {
                $done = 1 === \count($path);
            }

            if ($done) {
                $emit($value);
            }
        });
    }
}
