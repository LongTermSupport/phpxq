<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use Closure;

/**
 * A {@see StreamBuiltinInterface} that is also valid inside a path expression (`path(getpath(["a","b"]))`,
 * `path(first(.a,.b))`, `path(empty)`, `path(limit(1; .[]))`, `paths`-style walkers): it reports the
 * paths of its outputs. A builtin that is not a PathStreamBuiltin yields null paths in path mode.
 *
 * @internal
 */
interface PathStreamBuiltinInterface extends StreamBuiltinInterface
{
    /**
     * @param ?list<mixed>                       $path input path, null when the input is not path-derived
     * @param Closure(?list<mixed>, mixed): void $emit
     *
     * @throws JqException
     */
    public function runPaths(RuntimeContextInterface $context, ?array $path, mixed $input, Closure $emit, FilterInterface ...$args): void;
}
