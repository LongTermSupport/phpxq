<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `reduce source as pattern (init; update)`. One result per init output; the state after an update that
 * yields several outputs is the last one, after one that yields none it is null.
 *
 * @internal
 */
final readonly class ReduceOp implements OpInterface
{
    private ?SingleOpInterface $updateSingle;

    /**
     * @param ?AccumulatorUpdate $inPlace the update in its in-place form, when it has one; it must compute
     *                                    what $update computes
     */
    public function __construct(
        private OpInterface $source,
        private BinderInterface $binder,
        private OpInterface $init,
        private OpInterface $update,
        private ?AccumulatorUpdate $inPlace = null,
    ) {
        $this->updateSingle = $update instanceof SingleOpInterface ? $update : null;
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->init->run($env, $input, function (mixed $initial) use ($env, $input, $emit): void {
            $state   = $initial;
            $update  = $this->update;
            $single  = $this->updateSingle;
            $inPlace = $this->inPlace;
            SourceBindings::each($this->source, $this->binder, $env, $input, static function (?Env $bound) use (&$state, $update, $single, $inPlace): void {
                if ($inPlace instanceof AccumulatorUpdate) {
                    $inPlace->apply($bound, $state);

                    return;
                }

                if ($single instanceof SingleOpInterface) {
                    $state = $single->value($bound, $state);

                    return;
                }

                $next = null;
                $update->run($bound, $state, static function (mixed $value) use (&$next): void {
                    $next = $value;
                });
                $state = $next;
            });
            $emit($state);
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->init->paths($env, $path, $input, function (?array $initialPath, mixed $initial) use ($env, $input, $emit): void {
            $statePath = $initialPath;
            $state     = $initial;
            $update    = $this->update;
            SourceBindings::each($this->source, $this->binder, $env, $input, static function (?Env $bound) use (&$statePath, &$state, $update): void {
                $nextPath = null;
                $next     = null;
                $update->paths($bound, $statePath, $state, static function (?array $valuePath, mixed $value) use (&$nextPath, &$next): void {
                    $nextPath = $valuePath;
                    $next     = $value;
                });
                $statePath = $nextPath;
                $state     = $next;
            });
            $emit($statePath, $state);
        });
    }
}
