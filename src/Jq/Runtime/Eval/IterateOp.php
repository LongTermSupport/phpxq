<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Json\JsonObject;

/**
 * `target[]`; a null target is the input itself (`.[]`).
 *
 * @internal
 */
final readonly class IterateOp implements Op
{
    public function __construct(private ?Op $target)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        if (!$this->target instanceof Op) {
            self::each($input, $emit);

            return;
        }

        $this->target->run($env, $input, static function (mixed $value) use ($emit): void {
            self::each($value, $emit);
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        if (!$this->target instanceof Op) {
            self::eachPath($path, $input, $emit);

            return;
        }

        $this->target->paths($env, $path, $input, static function (?array $valuePath, mixed $value) use ($emit): void {
            self::eachPath($valuePath, $value, $emit);
        });
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function each(mixed $value, Closure $emit): void
    {
        if (\is_array($value)) {
            foreach ($value as $element) {
                $emit($element);
            }

            return;
        }

        if ($value instanceof JsonObject) {
            foreach ($value->values() as $member) {
                $emit($member);
            }

            return;
        }

        throw ErrorText::iterateError($value);
    }

    /**
     * @param ?list<mixed>                       $path
     * @param Closure(?list<mixed>, mixed): void $emit
     */
    private static function eachPath(?array $path, mixed $value, Closure $emit): void
    {
        if (null === $path) {
            throw PathErrors::iterate($value);
        }

        if (\is_array($value)) {
            foreach ($value as $index => $element) {
                $child   = $path;
                $child[] = $index;
                $emit($child, $element);
            }

            return;
        }

        if ($value instanceof JsonObject) {
            foreach ($value->entries() as $key => $member) {
                $child   = $path;
                $child[] = $key;
                $emit($child, $member);
            }

            return;
        }

        throw ErrorText::iterateError($value);
    }
}
