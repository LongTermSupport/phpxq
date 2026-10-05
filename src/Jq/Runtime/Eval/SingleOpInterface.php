<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * An {@see OpInterface} that produces exactly one output or throws. Parents use {@see self::value()} to avoid a
 * closure per output.
 *
 * @internal
 */
interface SingleOpInterface extends OpInterface
{
    /**
     * @throws JqException
     */
    public function value(?Env $env, mixed $input): mixed;
}
