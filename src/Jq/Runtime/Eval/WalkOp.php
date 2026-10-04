<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\BreakException;
use LTS\PhpXq\Json\JsonObject;
use stdClass;

/**
 * `walk(f)` for an `f` that may produce any number of outputs. Same meaning as the prelude definition
 * `def walk(f): def w: (if type == "object" then map_values(w) elif type == "array" then map(w) else . end) | f; w;`
 * without the path machinery of `|=`: an object member becomes the first output of `w` on it (and is dropped
 * when there is none), an array element becomes every output of `w`.
 *
 * Shaped for the `walk` workload in CLAUDE/Plan/00007-performance-optimisation-round/results.md: do not
 * replace it by the prelude definition.
 *
 * @internal
 */
final readonly class WalkOp implements OpInterface
{
    public function __construct(private OpInterface $filter)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->walk($env, $input, $emit);
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->walk($env, $input, static function (mixed $value) use ($emit): void {
            $emit(null, $value);
        });
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private function walk(?Env $env, mixed $value, Closure $emit): void
    {
        if ($value instanceof JsonObject) {
            $members = [];
            foreach ($value->toArray() as $key => $member) {
                $first = $this->first($env, $member);
                if ([] !== $first) {
                    $members[$key] = $first[0];
                }
            }

            $value = new JsonObject($members);
        } elseif (\is_array($value)) {
            $elements = [];
            $collect  = static function (mixed $output) use (&$elements): void {
                $elements[] = $output;
            };
            foreach ($value as $element) {
                $this->walk($env, $element, $collect);
            }

            $value = $elements;
        }

        $this->filter->run($env, $value, $emit);
    }

    /**
     * The first output of the walk of $value as a one element list, or [] when it has none. Later outputs
     * are never computed, as `|=` takes only the first.
     *
     * @return list<mixed>
     */
    private function first(?Env $env, mixed $value): array
    {
        $outcome = [];
        $label   = new stdClass();
        $stop    = new BreakException($label);

        try {
            $this->walk($env, $value, static function (mixed $output) use (&$outcome, $stop): never {
                $outcome = [$output];

                throw $stop;
            });
        } catch (BreakException $breakException) {
            if ($breakException->label !== $label) {
                throw $breakException;
            }
        }

        return $outcome;
    }
}
