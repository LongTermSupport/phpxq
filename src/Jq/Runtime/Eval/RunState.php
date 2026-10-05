<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LogicException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonObject;

/**
 * Mutable state shared by every op a {@see Core} compiles: the runtime context of the program currently
 * running and its global variables. Ops reach it through a reference captured at compile time, so the Op
 * trees stay independent of any particular run. It also counts the jq function and closure-parameter calls
 * in flight: more than MAX_CALL_DEPTH (twice the 10,000 levels jq's own depth-limit tests use) fail with
 * DEPTH_EXCEEDED, a jq error, before they can exhaust the native stack that {@see EvaluationStack} reserves.
 *
 * @internal
 */
final class RunState
{
    public const string DEPTH_EXCEEDED = 'Maximum evaluation depth exceeded';

    public const int MAX_CALL_DEPTH = 20000;

    private ?RuntimeContextInterface $context = null;

    /** @var array<string, mixed> */
    private array $globals = [];

    private ?JsonObject $environment = null;

    private int $callDepth = 0;

    /**
     * Account for one more jq function call on the native stack.
     *
     * @throws JqException when the calls in flight exceed {@see self::MAX_CALL_DEPTH}
     */
    public function enterCall(): void
    {
        if (++$this->callDepth > self::MAX_CALL_DEPTH) {
            --$this->callDepth;

            throw JqException::fromMessage(self::DEPTH_EXCEEDED);
        }
    }

    public function leaveCall(): void
    {
        --$this->callDepth;
    }

    public function context(): RuntimeContextInterface
    {
        return $this->context ?? throw new LogicException('No program is running');
    }

    /**
     * @param array<string, mixed> $globals
     */
    public function enter(?RuntimeContextInterface $context, array $globals): void
    {
        $this->context = $context;
        $this->globals = $globals;
    }

    public function global(string $name): mixed
    {
        if (\array_key_exists($name, $this->globals)) {
            return $this->globals[$name];
        }

        return match (ReservedGlobalEnum::tryFrom($name)) {
            ReservedGlobalEnum::Env      => $this->environment ??= JsonObject::fromPairs(getenv()),
            ReservedGlobalEnum::ProgArgs => [],
            default                      => null,
        };
    }

    /**
     * @return array{context: ?RuntimeContextInterface, globals: array<string, mixed>}
     */
    public function snapshot(): array
    {
        return ['context' => $this->context, 'globals' => $this->globals];
    }
}
