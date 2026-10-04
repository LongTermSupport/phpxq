<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Runtime\Eval\ErrorText;
use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * jq's error message vocabulary for builtins: a value shown in a message is dumped as compact JSON and
 * truncated, a type error reads `<type> (<dump>) <message>`.
 *
 * @internal
 */
final class Problems
{
    private function __construct()
    {
    }

    /**
     * Compact JSON, the form of `tojson`.
     */
    public static function json(mixed $value): string
    {
        return ErrorText::json($value);
    }

    /**
     * Compact JSON of at most 29 bytes, truncated and marked with `...`.
     */
    public static function dump(mixed $value): string
    {
        return ErrorText::dump($value);
    }

    public static function type(mixed $value, string $message): JqException
    {
        return ErrorText::typeError($value, $message);
    }

    public static function type2(mixed $left, mixed $right, string $message): JqException
    {
        return ErrorText::typeError2($left, $right, $message);
    }

    public static function iterate(mixed $target): JqException
    {
        return ErrorText::iterateError($target);
    }

    public static function index(mixed $target, mixed $key): JqException
    {
        return ErrorText::indexError($target, $key);
    }
}
