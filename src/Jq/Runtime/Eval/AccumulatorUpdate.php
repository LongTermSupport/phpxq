<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\PathOps;
use LTS\PhpXq\Json\JsonObject;

/**
 * A `reduce` or `foreach` update that changes the accumulator in place: `. + x` and `. += x` (append) or
 * `.[k] = x` (set key), where x and k each give exactly one value.
 *
 * The general operators build a new array or object on every step, which copies the whole accumulator and
 * makes a loop of n steps quadratic. Here the operand is computed first, exactly as the operator would, and
 * then written into the accumulator variable itself; PHP copies it only when something else still holds the
 * old value, so the result is always the one the operator gives.
 *
 * @internal
 */
final readonly class AccumulatorUpdate
{
    /**
     * @param ?SingleOpInterface $key the key of a set-key update; null for an append
     */
    private function __construct(
        private SingleOpInterface $operand,
        private ?SingleOpInterface $key,
    ) {
    }

    /**
     * `. + operand` or `. += operand`.
     */
    public static function append(SingleOpInterface $operand): self
    {
        return new self($operand, null);
    }

    /**
     * `.[key] = value`.
     */
    public static function setKey(SingleOpInterface $key, SingleOpInterface $value): self
    {
        return new self($value, $key);
    }

    /**
     * @throws JqException
     */
    public function apply(?Env $env, mixed &$state): void
    {
        $operand = $this->operand->value($env, $state);
        if ($this->key instanceof SingleOpInterface) {
            $this->set($state, $this->key->value($env, $state), $operand);

            return;
        }

        $this->add($state, $operand);
    }

    /**
     * What `Arithmetic::add($state, $operand)` gives, written into $state.
     *
     * @throws JqException
     */
    private function add(mixed &$state, mixed $operand): void
    {
        if (\is_array($state) && \is_array($operand)) {
            foreach ($operand as $value) {
                $state[\count($state)] = $value;
            }

            return;
        }

        if (\is_string($state) && \is_string($operand)) {
            $state .= $operand;

            return;
        }

        if ($state instanceof JsonObject && $operand instanceof JsonObject) {
            $members = $state->toArray();
            $state   = null;
            foreach ($operand->toArray() as $name => $value) {
                $members[$name] = $value;
            }

            $state = new JsonObject($members);

            return;
        }

        $state = Arithmetic::add($state, $operand);
    }

    /**
     * What `.[key] = value` gives: the key is checked against the accumulator as path collection does, then
     * set; an existing object member or array element, or the next array element, is written in place.
     *
     * @throws JqException
     */
    private function set(mixed &$state, mixed $key, mixed $value): void
    {
        if ($state instanceof JsonObject && \is_string($key)) {
            $members       = $state->toArray();
            $state         = null;
            $members[$key] = $value;
            $state         = new JsonObject($members);

            return;
        }

        if (\is_array($state) && \is_int($key) && $key >= 0 && $key <= \count($state)) {
            $state[$key] = $value;

            return;
        }

        Access::index($state, $key);
        PathOps::getPath($state, $key);
        $state = PathOps::setPath($state, $value, $key);
    }
}
