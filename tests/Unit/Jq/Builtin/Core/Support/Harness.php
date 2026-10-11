<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support;

use InvalidArgumentException;
use LogicException;
use LTS\PhpXq\Jq\Builtin\CoreBuiltins;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\PathStreamBuiltinInterface;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Jq\Runtime\StreamBuiltinInterface;
use LTS\PhpXq\Jq\Runtime\ValueBuiltinInterface;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Tests\Support\Jq\EagerBuiltins;

/**
 * Looks builtins up in a registry filled by {@see CoreBuiltins} and runs them directly, the way the
 * evaluator does, so each test names the builtin and its arguments instead of constructing classes.
 *
 * @internal
 */
final class Harness
{
    private static ?BuiltinRegistryInterface $registry = null;

    private function __construct()
    {
    }

    public static function registry(): BuiltinRegistryInterface
    {
        if (!self::$registry instanceof BuiltinRegistryInterface) {
            $registry = new DefaultBuiltinRegistry();
            EagerBuiltins::core($registry);
            self::$registry = $registry;
        }

        return self::$registry;
    }

    /**
     * Parse JSON text into the value model.
     */
    public static function json(string $text): mixed
    {
        return new JsonDecoder()->decodeOne($text);
    }

    /**
     * Run a value builtin once with already evaluated arguments.
     *
     * @param list<mixed> $args
     */
    public static function call(string $name, mixed $input, array $args = [], ?RuntimeContextInterface $context = null): mixed
    {
        $builtin = self::registry()->lookup($name, \count($args));
        if (!$builtin instanceof ValueBuiltinInterface) {
            throw new InvalidArgumentException($name . '/' . \count($args) . ' is not a value builtin');
        }

        return $builtin->call($context ?? new FakeContext(), $input, ...$args);
    }

    /**
     * The value of the jq error a value builtin raises.
     *
     * @param list<mixed> $args
     */
    public static function error(string $name, mixed $input, array $args = []): mixed
    {
        try {
            self::call($name, $input, $args);
        } catch (JqException $jqException) {
            return $jqException->value;
        }

        throw new LogicException($name . ' did not raise an error');
    }

    /**
     * The value of the jq error a stream builtin raises.
     *
     * @param list<FilterInterface> $filters
     */
    public static function streamError(string $name, mixed $input, array $filters = [], ?RuntimeContextInterface $context = null): mixed
    {
        try {
            self::stream($name, $input, $filters, $context);
        } catch (JqException $jqException) {
            return $jqException->value;
        }

        throw new LogicException($name . ' did not raise an error');
    }

    /**
     * Run a stream builtin and collect everything it emits.
     *
     * @param list<FilterInterface> $filters
     *
     * @return list<mixed>
     */
    public static function stream(string $name, mixed $input, array $filters = [], ?RuntimeContextInterface $context = null): array
    {
        $builtin = self::registry()->lookup($name, \count($filters));
        if (!$builtin instanceof StreamBuiltinInterface) {
            throw new InvalidArgumentException($name . '/' . \count($filters) . ' is not a stream builtin');
        }

        $out = [];
        $builtin->run($context ?? new FakeContext(), $input, static function (mixed $value) use (&$out): void {
            $out[] = $value;
        }, ...$filters);

        return $out;
    }

    /**
     * Run a path-aware stream builtin in path mode and collect `[path, value]` pairs.
     *
     * @param ?list<mixed>          $path
     * @param list<FilterInterface> $filters
     *
     * @return list<array{?list<mixed>, mixed}>
     */
    public static function paths(string $name, ?array $path, mixed $input, array $filters = []): array
    {
        $builtin = self::registry()->lookup($name, \count($filters));
        if (!$builtin instanceof PathStreamBuiltinInterface) {
            throw new InvalidArgumentException($name . '/' . \count($filters) . ' is not a path builtin');
        }

        $out = [];
        $builtin->runPaths(new FakeContext(), $path, $input, static function (?array $where, mixed $value) use (&$out): void {
            $out[] = [$where, $value];
        }, ...$filters);

        return $out;
    }
}
