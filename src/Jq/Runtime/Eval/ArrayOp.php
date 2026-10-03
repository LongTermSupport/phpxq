<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

/**
 * `[body]`: collects every output of body; `[]` when there is no body.
 *
 * @internal
 */
final class ArrayOp extends AbstractSingleOp
{
    private readonly ?SingleOp $single;

    public function __construct(private readonly ?Op $body)
    {
        $this->single = $body instanceof SingleOp ? $body : null;
    }

    public function value(?Env $env, mixed $input): mixed
    {
        if (null === $this->body) {
            return [];
        }

        if (null !== $this->single) {
            return [$this->single->value($env, $input)];
        }

        $items = [];
        $this->body->run($env, $input, static function (mixed $value) use (&$items): void {
            $items[] = $value;
        });

        return $items;
    }
}
