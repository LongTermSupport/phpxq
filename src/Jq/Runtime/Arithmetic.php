<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use LogicException;

/**
 * jq's `+ - * / %` and unary minus over the value model, with jq's type rules and error messages
 * ("number (1) and string (\"a\") cannot be added"). Shared by the evaluator and by builtins such as
 * `add`. OWNER: evaluator-core worker. Skeleton only: keep these signatures when implementing.
 *
 * @api
 */
final class Arithmetic
{
    private function __construct()
    {
    }

    /**
     * Raises a JqException on a type error once implemented.
     */
    public static function add(mixed $left, mixed $right): mixed
    {
        throw self::notImplemented('add', $left, $right);
    }

    /**
     * Raises a JqException on a type error once implemented.
     */
    public static function subtract(mixed $left, mixed $right): mixed
    {
        throw self::notImplemented('subtract', $left, $right);
    }

    /**
     * Raises a JqException on a type error once implemented.
     */
    public static function multiply(mixed $left, mixed $right): mixed
    {
        throw self::notImplemented('multiply', $left, $right);
    }

    /**
     * Raises a JqException on a type error once implemented.
     */
    public static function divide(mixed $left, mixed $right): mixed
    {
        throw self::notImplemented('divide', $left, $right);
    }

    /**
     * Raises a JqException on a type error once implemented.
     */
    public static function modulo(mixed $left, mixed $right): mixed
    {
        throw self::notImplemented('modulo', $left, $right);
    }

    /**
     * Raises a JqException on a type error once implemented.
     */
    public static function negate(mixed $value): mixed
    {
        throw self::notImplemented('negate', $value);
    }

    private static function notImplemented(string $operation, mixed ...$operands): LogicException
    {
        return new LogicException(\sprintf('Arithmetic::%s is not implemented (%d operands)', $operation, \count($operands)));
    }
}
