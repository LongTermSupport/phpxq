<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;

/**
 * `source as $name | body`.
 *
 * @internal
 */
final readonly class BindVarOp implements OpInterface
{
    public function __construct(
        private OpInterface $source,
        private OpInterface $body,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $body = $this->body;
        if ($this->source instanceof SingleOpInterface) {
            $body->run(new Env($env, $this->source->value($env, $input)), $input, $emit);

            return;
        }

        $this->source->run($env, $input, static function (mixed $value) use ($body, $env, $input, $emit): void {
            $body->run(new Env($env, $value), $input, $emit);
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $body = $this->body;
        $this->source->run($env, $input, static function (mixed $value) use ($body, $env, $path, $input, $emit): void {
            $body->paths(new Env($env, $value), $path, $input, $emit);
        });
    }
}
