<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Core;

use Closure;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\HaltException;
use LTS\PhpXq\Jq\Runtime\InputPositionInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\RuntimeContextInterface;
use LTS\PhpXq\Json\JsonObject;

/**
 * Builtins that reach outside the value: input and inputs, debug, stderr, input_filename, halt and
 * halt_error, env, get_search_list and builtins.
 *
 * @internal
 */
final readonly class IoFunctions
{
    private function __construct()
    {
    }

    /**
     * @param Closure(): list<string> $catalog the `name/arity` strings `builtins` reports, built when first called
     */
    public static function register(BuiltinRegistryInterface $registry, Closure $catalog): void
    {
        $registry->register(new StreamFunction('input', 0, static function (RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void {
            $emit($c->inputs()->next());
        }));
        $registry->register(new StreamFunction('inputs', 0, static function (RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void {
            $inputs = $c->inputs();
            while ($inputs->hasNext()) {
                $emit($inputs->next());
            }
        }));
        $registry->register(new StreamFunction('debug', 0, static function (RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void {
            $c->debug($input);
            $emit($input);
        }));
        $registry->register(new StreamFunction('debug', 1, self::debugWith(...)));
        $registry->register(new StreamFunction('stderr', 0, static function (RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void {
            $c->writeStderr($input);
            $emit($input);
        }));
        $registry->register(new ValueFunction('input_filename', 0, static fn (RuntimeContextInterface $c): mixed => $c->inputFilename()));
        $registry->register(new ValueFunction('input_line_number', 0, static function (RuntimeContextInterface $c): mixed {
            $inputs = $c->inputs();

            return $inputs instanceof InputPositionInterface ? $inputs->lineNumber() : 0;
        }));
        $registry->register(new ValueFunction('halt', 0, static function (): never {
            throw new HaltException(0);
        }));
        $registry->register(new ValueFunction('halt_error', 1, static function (RuntimeContextInterface $c, mixed $input, array $args): never {
            if (!Num::isNumber($args[0])) {
                throw new JqException('halt_error/1: number required');
            }

            $status = Num::toInt(Num::toFloat($args[0]));
            if (null === $input) {
                throw new HaltException($status);
            }

            throw new HaltException($status, \is_string($input) ? $input : Problems::json($input) . "\n");
        }));
        $registry->register(new ValueFunction('env', 0, static function (RuntimeContextInterface $c): mixed {
            $globals = $c->globals();

            return $globals['ENV'] ?? JsonObject::fromPairs(getenv());
        }));
        $registry->register(new ValueFunction('get_search_list', 0, static fn (RuntimeContextInterface $c): mixed => $c->libraryPaths()));
        $registry->register(new ValueFunction('builtins', 0, static fn () => $catalog()));
    }

    /**
     * @param Closure(mixed): void $emit
     */
    private static function debugWith(RuntimeContextInterface $c, mixed $input, Closure $emit, FilterInterface ...$args): void
    {
        $args[0]->run($input, static function (mixed $message) use ($c): void {
            $c->debug($message);
        });
        $emit($input);
    }
}
