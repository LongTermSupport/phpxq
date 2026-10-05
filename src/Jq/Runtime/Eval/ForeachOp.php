<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `foreach source as pattern (init; update; extract)`: for every update output the extract expression (or
 * the output itself) is emitted and the output becomes the new state; an update without output leaves null.
 *
 * @internal
 */
final readonly class ForeachOp implements OpInterface
{
    public function __construct(
        private OpInterface $source,
        private BinderInterface $binder,
        private OpInterface $init,
        private OpInterface $update,
        private ?OpInterface $extract,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->init->run($env, $input, function (mixed $initial) use ($env, $input, $emit): void {
            $state   = $initial;
            $update  = $this->update;
            $extract = $this->extract;
            SourceBindings::each($this->source, $this->binder, $env, $input, static function (?Env $bound) use (&$state, $update, $extract, $emit): void {
                $current = $state;
                $state   = null;
                $update->run($bound, $current, static function (mixed $value) use (&$state, $extract, $bound, $emit): void {
                    $state = $value;
                    if (!$extract instanceof OpInterface) {
                        $emit($value);

                        return;
                    }

                    $extract->run($bound, $value, $emit);
                });
            });
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->init->paths($env, $path, $input, function (?array $initialPath, mixed $initial) use ($env, $input, $emit): void {
            $statePath = $initialPath;
            $state     = $initial;
            $update    = $this->update;
            $extract   = $this->extract;
            SourceBindings::each($this->source, $this->binder, $env, $input, static function (?Env $bound) use (&$statePath, &$state, $update, $extract, $emit): void {
                $currentPath = $statePath;
                $current     = $state;
                $statePath   = null;
                $state       = null;
                $update->paths($bound, $currentPath, $current, static function (?array $valuePath, mixed $value) use (&$statePath, &$state, $extract, $bound, $emit): void {
                    $statePath = $valuePath;
                    $state     = $value;
                    if (!$extract instanceof OpInterface) {
                        $emit($valuePath, $value);

                        return;
                    }

                    $extract->paths($bound, $valuePath, $value, $emit);
                });
            });
        });
    }
}
