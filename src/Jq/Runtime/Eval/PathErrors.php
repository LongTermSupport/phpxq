<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * The "Invalid path expression" errors raised when a path operation is applied to a computed value.
 *
 * @internal
 */
final readonly class PathErrors
{
    private function __construct()
    {
    }

    public static function access(mixed $key, mixed $target): JqException
    {
        return new JqException(\sprintf(
            'Invalid path expression near attempt to access element %s of %s',
            ErrorText::dump($key),
            ErrorText::dump($target),
        ));
    }

    public static function iterate(mixed $target): JqException
    {
        return new JqException('Invalid path expression near attempt to iterate through ' . ErrorText::dump($target));
    }

    public static function result(mixed $value): JqException
    {
        return new JqException('Invalid path expression with result ' . ErrorText::dump($value));
    }
}
