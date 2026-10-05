<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\JsonObject;

/**
 * `{k: v, ...}` where every key and value produces exactly one output.
 *
 * @internal
 */
final class SingleObjectOp extends AbstractSingleOp
{
    /**
     * @param list<array{SingleOpInterface, SingleOpInterface}> $entries
     */
    public function __construct(private readonly array $entries)
    {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        $members = [];
        foreach ($this->entries as [$keyOp, $valueOp]) {
            $key = $keyOp->value($env, $input);
            if (!\is_string($key)) {
                throw new JqException('Object keys must be strings');
            }

            $members[$key] = $valueOp->value($env, $input);
        }

        return new JsonObject($members);
    }
}
