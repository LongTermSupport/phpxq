<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Fixtures\Defence\LoopConstruction;

use ArrayObject;
use LTS\PhpXq\Jq\Builtin\DefaultBuiltinRegistry;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Yaml\Parser\YamlParser;

/**
 * Construction that is hoisted, memoised, cheap, or different on every iteration.
 */
final class BuildsOnceOrPerInput
{
    private ?DefaultBuiltinRegistry $registry = null;

    private ?JsonDecoder $decoder = null;

    /**
     * @param list<int> $numbers
     */
    public function hoisted(array $numbers): void
    {
        $registry = new DefaultBuiltinRegistry();
        foreach ($numbers as $number) {
            $registry->lookup('x', $number);
        }
    }

    /**
     * @param list<string> $documents
     */
    public function dependsOnTheIteration(array $documents): void
    {
        foreach ($documents as $document) {
            new YamlParser($document)->parse();
            new JsonDecoder($document);
        }
    }

    /**
     * @param list<int> $numbers
     */
    public function cheapAndNotAnEngine(array $numbers): void
    {
        foreach ($numbers as $number) {
            $bag = new ArrayObject([$number]);
            $bag->count();
        }
    }

    /**
     * @param list<int> $days
     */
    public function memoisedHelper(array $days): void
    {
        foreach ($days as $day) {
            $this->registry()->lookup('x', $day);
            $this->decoder()->decodeOne('1');
        }
    }

    /**
     * @param list<int> $days
     */
    public function exitsOnTheFirstPass(array $days): DefaultBuiltinRegistry
    {
        foreach ($days as $day) {
            if ($day > 0) {
                return new DefaultBuiltinRegistry();
            }
        }

        return new DefaultBuiltinRegistry();
    }

    /**
     * @param list<int> $days
     */
    public function firstElementOnly(array $days): int
    {
        foreach ($days as $day) {
            $registry = new DefaultBuiltinRegistry();

            return null === $registry->lookup('x', $day) ? 0 : 1;
        }

        return -1;
    }

    private function registry(): DefaultBuiltinRegistry
    {
        if (!$this->registry instanceof DefaultBuiltinRegistry) {
            $this->registry = new DefaultBuiltinRegistry();
        }

        return $this->registry;
    }

    private function decoder(): JsonDecoder
    {
        return $this->decoder ??= new JsonDecoder();
    }
}
