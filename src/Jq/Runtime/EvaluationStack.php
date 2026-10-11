<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

use Closure;
use Fiber;

/**
 * Runs an evaluation on a native stack of known size. Under Xdebug in coverage mode every PHP call also uses
 * the native stack (0.8 to 1.3 KB per jq call level for a simple body, up to 8 KB for one nesting reduce,
 * foreach, try and if), so the process stack limit (8 MB by default) would overflow near 7,000 nested
 * calls. A fiber has its own stack, sized here at BYTES_PER_CALL for the whole MAX_CALL_DEPTH budget and
 * independent of `ulimit -s`. The evaluator counts jq function and closure-parameter calls in flight and
 * fails with DEPTH_EXCEEDED, a jq error, beyond MAX_CALL_DEPTH (twice the 10,000 levels jq's own
 * depth-limit tests use).
 *
 * A fiber costs about 80 microseconds to create, so a caller with many evaluations runs them all inside one
 * call of {@see self::run()}; a nested call reuses the running fiber.
 *
 * @internal
 */
final readonly class EvaluationStack
{
    public const int MAX_CALL_DEPTH = 20000;

    public const string DEPTH_EXCEEDED = 'Evaluation too deep';

    private const int BYTES_PER_CALL = 16384;

    private const string STACK_SIZE_SETTING = 'fiber.stack_size';

    private function __construct()
    {
    }

    /**
     * @template T
     *
     * @param Closure(): T $body
     *
     * @return T
     */
    public static function run(Closure $body): mixed
    {
        if (Fiber::getCurrent() instanceof Fiber) {
            return $body();
        }

        $previous = ini_set(self::STACK_SIZE_SETTING, (string)(self::MAX_CALL_DEPTH * self::BYTES_PER_CALL));
        $fiber    = new Fiber($body);

        try {
            $fiber->start();
        } finally {
            if (false !== $previous) {
                ini_set(self::STACK_SIZE_SETTING, $previous);
            }
        }

        return $fiber->getReturn();
    }
}
