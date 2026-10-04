<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\LoopConstruction;

use LTS\PhpXq\Jq\Builtin\DefaultBuiltinRegistry;
use LTS\PhpXq\Json\JsonDecoder;

/**
 * Construction inside loops written with named and spread arguments, a static closure and a first-class callable.
 */
final class OddSyntaxConstruction
{
    /**
     * @param list<int> $numbers
     */
    public function namedArgument(array $numbers): void
    {
        foreach ($numbers as $number) {
            new JsonDecoder(flags: 0);
        }
    }

    /**
     * @param list<int> $numbers
     */
    public function staticClosure(array $numbers): void
    {
        array_map(static function (int $number): int {
            new DefaultBuiltinRegistry();

            return $number;
        }, $numbers);
    }

    /**
     * @param list<int> $numbers
     */
    public function calledThroughANamedArgumentCallback(array $numbers): void
    {
        array_walk(callback: fn (int $number): int => $this->build() instanceof DefaultBuiltinRegistry ? $number : 0, array: $numbers);
    }

    private function build(): DefaultBuiltinRegistry
    {
        return new DefaultBuiltinRegistry();
    }
}
