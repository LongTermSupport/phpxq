<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\JqException;

/**
 * `left // right`: every truthy output of left, or, when there is none, the outputs of right. Errors raised
 * by left are swallowed (they end left's output); errors from the continuation are not.
 *
 * @internal
 */
final class AltOp implements Op
{
    public function __construct(
        private readonly Op $left,
        private readonly Op $right,
    ) {
    }

    public function run(?Env $env, mixed $input, Closure $emit): void
    {
        $found      = false;
        $downstream = false;
        try {
            $this->left->run($env, $input, static function (mixed $value) use (&$found, &$downstream, $emit): void {
                if (null === $value || false === $value) {
                    return;
                }

                $found      = true;
                $downstream = true;
                $emit($value);
                $downstream = false;
            });
        } catch (JqException $exception) {
            if ($downstream) {
                throw $exception;
            }
        }

        if (!$found) {
            $this->right->run($env, $input, $emit);
        }
    }

    public function paths(?Env $env, ?array $path, mixed $input, Closure $emit): void
    {
        $found      = false;
        $downstream = false;
        try {
            $this->left->paths($env, $path, $input, static function (?array $valuePath, mixed $value) use (&$found, &$downstream, $emit): void {
                if (null === $value || false === $value) {
                    return;
                }

                $found      = true;
                $downstream = true;
                $emit($valuePath, $value);
                $downstream = false;
            });
        } catch (JqException $exception) {
            if ($downstream) {
                throw $exception;
            }
        }

        if (!$found) {
            $this->right->paths($env, $path, $input, $emit);
        }
    }
}
