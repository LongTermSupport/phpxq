<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

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
    private readonly ?SingleOpInterface $single;

    public function __construct(
        private readonly OpInterface $left,
        private readonly OpInterface $update,
    ) {
        $this->single = $update instanceof SingleOpInterface ? $update : null;
    }

    public function value(?Env $env, mixed $input): mixed
    {
        $paths  = Assignment::collect($this->left, $env, $input);
        $single = $this->single;
        if ($single instanceof SingleOpInterface) {
            return Assignment::setAll($input, static fn (mixed $old): mixed => $single->value($env, $old), ...$paths);
        }

        $update = $this->update;
        $token  = new stdClass();
        $stop   = new BreakException($token);

        return Assignment::updateAll($input, static function (mixed $old) use ($update, $env, $token, $stop): array {
            $outcome = [];
            try {
                $update->run($env, $old, static function (mixed $value) use (&$outcome, $stop): never {
                    $outcome = [$value];

                    throw $stop;
                });
            } catch (BreakException $breakException) {
                if ($breakException->label !== $token) {
                    throw $breakException;
                }
            }

            return $outcome;
        }, ...$paths);
    }
}
