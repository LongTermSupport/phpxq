<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\Arithmetic;

/**
 * Unary minus over a generator.
 *
 * @internal
 */
final class NegateOp extends AbstractOp
{
    public function __construct(private readonly OpInterface $operand)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->operand->run($env, $input, static function (mixed $value) use ($emit): void {
            $emit(Arithmetic::negate($value));
        });
    }
}
