<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * A string with `\(...)` interpolations. Every output of every interpolated expression is converted with
 * the format function and spliced in; several outputs give the cartesian product with the last
 * interpolation as the outer loop.
 *
 * @internal
 */
final class StringInterpOp extends AbstractOp
{
    /** @var list<int> */
    private readonly array $expressions;

    /**
     * @param list<string|OpInterface> $parts
     * @param Closure(mixed): string   $format
     */
    public function __construct(
        private readonly array $parts,
        private readonly Closure $format,
    ) {
        $expressions = [];
        foreach ($parts as $index => $part) {
            if ($part instanceof OpInterface) {
                $expressions[] = $index;
            }
        }

        $this->expressions = array_reverse($expressions);
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->walk($env, $input, 0, [], $emit);
    }

    /**
     * @param array<int, string> $texts formatted text per part index
     */
    private function walk(?Env $env, mixed $input, int $position, array $texts, Closure $emit): void
    {
        if ($position === \count($this->expressions)) {
            $result = '';
            foreach ($this->parts as $index => $part) {
                $result .= \is_string($part) ? $part : $texts[$index];
            }

            $emit($result);

            return;
        }

        $index = $this->expressions[$position];
        $part  = $this->parts[$index];
        \assert($part instanceof OpInterface);
        $format = $this->format;
        $part->run($env, $input, function (mixed $value) use ($format, $env, $input, $position, $index, $texts, $emit): void {
            $texts[$index] = $format($value);
            $this->walk($env, $input, $position + 1, $texts, $emit);
        });
    }
}
