<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\BreakException;
use stdClass;

/**
 * `left |= update`: every path of left is replaced by the first output of update applied to its value; a
 * path whose update yields nothing is deleted.
 *
 * @internal
 */
final class UpdateAssignOp extends AbstractSingleOp
{
    private readonly ?SingleOp $single;

    public function __construct(
        private readonly Op $left,
        private readonly Op $update,
    ) {
        $this->single = $update instanceof SingleOp ? $update : null;
    }

    public function value(?Env $env, mixed $input): mixed
    {
        $paths = Assignment::collect($this->left, $env, $input);
        $single = $this->single;
        if (null !== $single) {
            return Assignment::setAll($input, $paths, static fn (mixed $old): mixed => $single->value($env, $old));
        }

        $update = $this->update;
        $token  = new stdClass();
        $stop   = new BreakException($token);

        return Assignment::updateAll($input, $paths, static function (mixed $old) use ($update, $env, $token, $stop): array {
            $outcome = [];
            try {
                $update->run($env, $old, static function (mixed $value) use (&$outcome, $stop): void {
                    $outcome = [$value];

                    throw $stop;
                });
            } catch (BreakException $exception) {
                if ($exception->label !== $token) {
                    throw $exception;
                }
            }

            return $outcome;
        });
    }
}
