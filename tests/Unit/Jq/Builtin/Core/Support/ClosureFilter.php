<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core\Support;

use Closure;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\Values;
use RuntimeException;

/**
 * A {@see FilterInterface} built from closures, so builtins taking filter arguments can be exercised without the
 * evaluator.
 *
 * @internal
 */
final readonly class ClosureFilter implements FilterInterface
{
    /**
     * @param Closure(mixed, Closure(mixed): void): void                              $values
     * @param ?Closure(?list<mixed>, mixed, Closure(?list<mixed>, mixed): void): void $paths
     */
    public function __construct(
        private Closure $values,
        private ?Closure $paths = null,
    ) {
    }

    /**
     * A filter producing the given constants whatever the input (a generator `a, b, c`).
     */
    public static function constants(mixed ...$outputs): self
    {
        return new self(
            static function (mixed $input, Closure $emit) use ($outputs): void {
                foreach ($outputs as $output) {
                    $emit($output);
                }
            },
        );
    }

    /**
     * `.`.
     */
    public static function identity(): self
    {
        return new self(
            static function (mixed $input, Closure $emit): void {
                $emit($input);
            },
            static function (?array $path, mixed $input, Closure $emit): void {
                $emit($path, $input);
            },
        );
    }

    /**
     * `.[]`.
     */
    public static function iterate(): self
    {
        return new self(
            static function (mixed $input, Closure $emit): void {
                foreach (self::children($input) as $child) {
                    $emit($child);
                }
            },
            static function (?array $path, mixed $input, Closure $emit): void {
                foreach (self::children($input) as $key => $child) {
                    $emit(null === $path ? null : [...$path, $key], $child);
                }
            },
        );
    }

    /**
     * A one-output function of the input.
     *
     * @param Closure(mixed): mixed $function
     */
    public static function of(Closure $function): self
    {
        return new self(static function (mixed $input, Closure $emit) use ($function): void {
            $emit($function($input));
        });
    }

    /**
     * `.[$key]`.
     */
    public static function field(string $key): self
    {
        return new self(
            static function (mixed $input, Closure $emit) use ($key): void {
                $emit($input instanceof JsonObject ? $input->get($key) : null);
            },
            static function (?array $path, mixed $input, Closure $emit) use ($key): void {
                $emit(null === $path ? null : [...$path, $key], $input instanceof JsonObject ? $input->get($key) : null);
            },
        );
    }

    public function run(mixed $input, Closure $emit): void
    {
        ($this->values)($input, $emit);
    }

    public function paths(?array $path, mixed $input, Closure $emit): void
    {
        if (!$this->paths instanceof Closure) {
            $this->run($input, static function (mixed $value) use ($emit): void {
                $emit(null, $value);
            });

            return;
        }

        ($this->paths)($path, $input, $emit);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function children(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        if ($value instanceof JsonObject) {
            $out = [];
            foreach ($value->entries() as $key => $child) {
                $out[$key] = $child;
            }

            return $out;
        }

        throw new RuntimeException('Cannot iterate over ' . Values::typeName($value));
    }
}
