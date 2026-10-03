<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\BreakException;
use stdClass;

/**
 * Early termination of a generator (`first`, `limit`, `isempty`, `any`, `all`): a body runs under a fresh
 * label, calls {@see self::stop()} to end the whole run, and a foreign break passes through untouched.
 *
 * @internal
 */
final class EarlyExit
{
    private function __construct()
    {
    }

    /**
     * Run $body with a fresh label.
     *
     * @param Closure(object): void $body
     *
     * @return bool true when the body ended through {@see self::stop()} with its own label
     */
    public static function run(Closure $body): bool
    {
        $label = new stdClass();

        try {
            $body($label);
        } catch (BreakException $breakException) {
            if ($breakException->label !== $label) {
                throw $breakException;
            }

            return true;
        }

        return false;
    }

    public static function stop(object $label): never
    {
        throw new BreakException($label);
    }
}
