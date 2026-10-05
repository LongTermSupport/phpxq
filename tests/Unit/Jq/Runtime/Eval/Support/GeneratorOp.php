<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use Closure;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\OpInterface;

/**
 * An op emitting a fixed list of values in value mode and in path mode (as computed, path-less values).
 */
final readonly class GeneratorOp implements OpInterface
{
    /**
     * @param list<mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        foreach ($this->values as $value) {
            $emit($value);
        }
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        foreach ($this->values as $value) {
            $emit(null, $value);
        }
    }
}
