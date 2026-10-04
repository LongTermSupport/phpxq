<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\LoopConstruction;

use LTS\PhpXq\Jq\Builtin\DefaultBuiltinRegistry;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;

/**
 * Every loop here rebuilds an expensive object whose construction does not depend on the iteration.
 */
final class ConstructsPerIteration
{
    /**
     * @param list<int> $numbers
     */
    public function directInLoop(array $numbers): void
    {
        foreach ($numbers as $number) {
            $registry = new DefaultBuiltinRegistry();
            $registry->prelude();
            unset($number);
        }
    }

    /**
     * @param list<string> $texts
     */
    public function invariantArgument(array $texts): void
    {
        $decoder = new JsonDecoder();
        $index   = 0;
        while ($index < \count($texts)) {
            $encoder = new JsonEncoder($decoder);
            $encoder->encode($texts[$index]);
            ++$index;
        }
    }

    /**
     * @param list<int> $numbers
     *
     * @return list<DefaultBuiltinRegistry>
     */
    public function insideACallback(array $numbers): array
    {
        return array_map(static fn (int $number): DefaultBuiltinRegistry => new DefaultBuiltinRegistry(), $numbers);
    }

    public function throughHelpers(): void
    {
        for ($day = 0; $day < 24000; ++$day) {
            $this->call($day);
        }
    }

    private function call(int $day): mixed
    {
        return $this->registry()->lookup('x', $day);
    }

    private function registry(): DefaultBuiltinRegistry
    {
        return new DefaultBuiltinRegistry();
    }
}
