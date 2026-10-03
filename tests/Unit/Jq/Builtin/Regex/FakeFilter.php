<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Regex;

use Closure;
use LTS\PhpXq\Jq\Runtime\Filter;

/**
 * A closure-backed {@see Filter} for builtin tests: the closure maps an input to the list of outputs.
 *
 * @internal
 */
final readonly class FakeFilter implements Filter
{
    /**
     * @param Closure(mixed): list<mixed> $outputs
     */
    private function __construct(private Closure $outputs)
    {
    }

    /**
     * A filter that ignores its input and yields the given values.
     */
    public static function yielding(mixed ...$values): self
    {
        return new self(static fn (): array => array_values($values));
    }

    /**
     * @param Closure(mixed): list<mixed> $outputs
     */
    public static function from(Closure $outputs): self
    {
        return new self($outputs);
    }

    public function run(mixed $input, Closure $emit): void
    {
        foreach (($this->outputs)($input) as $value) {
            $emit($value);
        }
    }

    public function paths(?array $path, mixed $input, Closure $emit): void
    {
        foreach (($this->outputs)($input) as $value) {
            $emit(null, $value);
        }
    }
}
