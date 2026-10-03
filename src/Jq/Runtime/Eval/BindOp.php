<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `source as pattern | body`: the body runs against the original input once per source output and per
 * combination the pattern generates. The source is evaluated in value mode, the body keeps path mode.
 *
 * @internal
 */
final readonly class BindOp implements OpInterface
{
    public function __construct(
        private OpInterface $source,
        private BinderInterface $binder,
        private OpInterface $body,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $binder = $this->binder;
        $body   = $this->body;
        $this->source->run($env, $input, static function (mixed $value) use ($binder, $body, $env, $input, $emit): void {
            $binder->bind($env, $value, static function (?Env $bound) use ($body, $input, $emit): void {
                $body->run($bound, $input, $emit);
            });
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $binder = $this->binder;
        $body   = $this->body;
        $this->source->run($env, $input, static function (mixed $value) use ($binder, $body, $env, $path, $input, $emit): void {
            $binder->bind($env, $value, static function (?Env $bound) use ($body, $path, $input, $emit): void {
                $body->paths($bound, $path, $input, $emit);
            });
        });
    }
}
