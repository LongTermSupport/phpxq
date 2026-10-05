<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use Closure;
use Fiber;

/**
 * Runs an evaluation on a native stack of known size. Under Xdebug in coverage mode every PHP call also uses
 * the native stack (0.8 to 1.3 KB per jq call level for a simple body, up to 8 KB for one nesting reduce,
 * foreach, try and if), so the process stack limit (8 MB by default) would overflow near 7,000 nested
 * calls. A fiber has its own stack, sized here at BYTES_PER_CALL for the whole {@see RunState::MAX_CALL_DEPTH}
 * budget and independent of `ulimit -s`.
 *
 * @internal
 */
final readonly class EvaluationStack
{
    private const int BYTES_PER_CALL = 16384;

    private const string STACK_SIZE_SETTING = 'fiber.stack_size';

    private function __construct()
    {
    }

    /**
     * @param Closure(): void $body
     */
    public static function run(Closure $body): void
    {
        if (Fiber::getCurrent() instanceof Fiber) {
            $body();

            return;
        }

        $previous = ini_set(self::STACK_SIZE_SETTING, (string)(RunState::MAX_CALL_DEPTH * self::BYTES_PER_CALL));
        $fiber    = new Fiber($body);

        try {
            $fiber->start();
        } finally {
            if (false !== $previous) {
                ini_set(self::STACK_SIZE_SETTING, $previous);
            }
        }
    }
}
