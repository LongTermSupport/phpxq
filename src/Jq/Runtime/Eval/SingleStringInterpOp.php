<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * A string interpolation whose expressions each produce exactly one output.
 *
 * @internal
 */
final class SingleStringInterpOp extends AbstractSingleOp
{
    /**
     * @param list<string|SingleOp>  $parts
     * @param Closure(mixed): string $format
     */
    public function __construct(
        private readonly array $parts,
        private readonly Closure $format,
    ) {
    }

    public function value(?Env $env, mixed $input): mixed
    {
        $values = [];
        foreach ($this->parts as $index => $part) {
            if ($part instanceof SingleOp) {
                $values[$index] = $part->value($env, $input);
            }
        }

        $result = '';
        foreach ($this->parts as $index => $part) {
            $result .= \is_string($part) ? $part : ($this->format)($values[$index]);
        }

        return $result;
    }
}
