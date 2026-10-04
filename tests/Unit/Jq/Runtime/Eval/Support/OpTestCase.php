<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use LTS\PhpXq\Jq\Runtime\Eval\ConstOp;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\IdentityOp;
use LTS\PhpXq\Jq\Runtime\Eval\OpInterface;
use LTS\PhpXq\Jq\Runtime\Eval\SingleOpInterface;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\TestCase;

/**
 * Helpers for testing ops directly: collecting what an op emits in value and in path mode, and building the
 * small operand ops tests need.
 */
abstract class OpTestCase extends TestCase
{
    /**
     * @return list<mixed>
     */
    protected static function outputs(OpInterface $op, mixed $input = null, ?Env $env = null): array
    {
        $outputs = [];
        $op->run($env, $input, static function (mixed $value) use (&$outputs): void {
            $outputs[] = $value;
        });

        return $outputs;
    }

    /**
     * @param ?list<mixed> $path
     *
     * @return list<array{?list<mixed>, mixed}>
     */
    protected static function pathOutputs(OpInterface $op, mixed $input = null, ?array $path = [], ?Env $env = null): array
    {
        $outputs = [];
        $op->paths($env, $path, $input, static function (?array $valuePath, mixed $value) use (&$outputs): void {
            $outputs[] = [$valuePath, $value];
        });

        return $outputs;
    }

    protected static function constant(mixed $value): ConstOp
    {
        return new ConstOp($value);
    }

    protected static function identity(): IdentityOp
    {
        return new IdentityOp();
    }

    /**
     * An op that emits each of $values, in order (a generator that is not a {@see SingleOpInterface}).
     */
    protected static function generator(mixed ...$values): OpInterface
    {
        return new GeneratorOp(array_values($values));
    }

    /**
     * @param array<string, mixed> $members
     */
    protected static function object(array $members): JsonObject
    {
        return new JsonObject($members);
    }
}
