<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Json\JsonObject;

/**
 * `walk(f)` when `f` always produces exactly one output: no generators, no early exit, no closures per node.
 * Same meaning as {@see WalkOp}.
 *
 * @internal
 */
final class SingleWalkOp extends AbstractSingleOp
{
    public function __construct(private readonly SingleOpInterface $filter)
    {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        if ($input instanceof JsonObject) {
            $members = $input->toArray();
            foreach ($members as $key => $member) {
                $members[$key] = $this->value($env, $member);
            }

            return $this->filter->value($env, new JsonObject($members));
        }

        if (\is_array($input)) {
            foreach ($input as $index => $element) {
                $input[$index] = $this->value($env, $element);
            }
        }

        return $this->filter->value($env, $input);
    }
}
