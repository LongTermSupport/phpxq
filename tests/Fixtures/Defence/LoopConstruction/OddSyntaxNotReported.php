<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\LoopConstruction;

use LTS\PhpXq\Jq\Builtin\DefaultBuiltinRegistry;
use LTS\PhpXq\Json\JsonDecoder;

interface OddSyntaxBuilder
{
    public function build(): DefaultBuiltinRegistry;
}

/**
 * Shapes the rule cannot judge or that are fine; none of them may crash it.
 */
abstract class OddSyntaxNotReported implements OddSyntaxBuilder
{
    abstract public function nothing(): void;

    /**
     * @param list<int>    $numbers
     * @param class-string $class
     */
    public function dynamicAndSpread(array $numbers, string $class, string $name, array $arguments): void
    {
        foreach ($numbers as $number) {
            new $class();
            new JsonDecoder(...[$number, ...$arguments]);
            new JsonDecoder(flags: $number);
            new class {
            };
            $this->{$name}();
            $class::build();
            $this->build();
            $callable = $this->build(...);
        }
    }

    /**
     * @param list<int> $numbers
     */
    public function firstClassCallableIterator(array $numbers): array
    {
        return array_map($this->build(...), $numbers);
    }
}
