<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\PathOps;

/**
 * `getpath(path)`; in a path expression it extends the current path.
 *
 * @internal
 */
final readonly class GetPathOp implements OpInterface
{
    public function __construct(private OpInterface $argument)
    {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $this->argument->run($env, $input, static function (mixed $path) use ($input, $emit): void {
            if (!\is_array($path) || !array_is_list($path)) {
                throw new JqException('Path must be specified as an array');
            }

            $emit(PathOps::getPath($input, ...$path));
        });
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $this->argument->run($env, $input, static function (mixed $steps) use ($path, $input, $emit): void {
            if (!\is_array($steps) || !array_is_list($steps)) {
                throw new JqException('Path must be specified as an array');
            }

            $value = PathOps::getPath($input, ...$steps);
            $emit(null === $path ? null : array_merge($path, $steps), $value);
        });
    }
}
