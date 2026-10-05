<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonObject;

/**
 * `{k: v, ...}`. Keys and values may generate several outputs; the result is the cartesian product in which
 * the first entry varies slowest and, inside an entry, the key is the outer loop.
 *
 * @internal
 */
final class ObjectOp extends AbstractOp
{
    /** @var list<bool> */
    private readonly array $single;

    private readonly int $count;

    /**
     * @param list<array{OpInterface, OpInterface}> $entries key and value expression of every entry
     */
    public function __construct(private readonly array $entries)
    {
        $single = [];
        foreach ($entries as [$key, $value]) {
            $single[] = $key instanceof SingleOpInterface && $value instanceof SingleOpInterface;
        }

        $this->single = $single;
        $this->count  = \count($entries);
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->step($env, $input, 0, [], $emit);
    }

    /**
     * @param array<array-key, mixed> $members
     *
     * @throws JqException
     */
    private function step(?Env $env, mixed $input, int $index, array $members, Closure $emit): void
    {
        while ($index < $this->count && $this->single[$index]) {
            [$keyOp, $valueOp] = $this->entries[$index];
            \assert($keyOp instanceof SingleOpInterface && $valueOp instanceof SingleOpInterface);
            $key = $keyOp->value($env, $input);
            if (!\is_string($key)) {
                throw new JqException('Object keys must be strings');
            }

            $members[$key] = $valueOp->value($env, $input);
            ++$index;
        }

        if ($index === $this->count) {
            $emit(new JsonObject($members));

            return;
        }

        [$keyOp, $valueOp] = $this->entries[$index];
        $keyOp->run($env, $input, function (mixed $key) use ($valueOp, $env, $input, $index, $members, $emit): void {
            if (!\is_string($key)) {
                throw new JqException('Object keys must be strings');
            }

            $valueOp->run($env, $input, function (mixed $value) use ($key, $env, $input, $index, $members, $emit): void {
                $members[$key] = $value;
                $this->step($env, $input, $index + 1, $members, $emit);
            });
        });
    }
}
