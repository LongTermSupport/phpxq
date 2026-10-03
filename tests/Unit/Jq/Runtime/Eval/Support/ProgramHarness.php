<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support;

use Closure;
use LogicException;
use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\BreakException;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\Compiler;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\FileModuleLoader;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Jq\Runtime\ModuleLoaderInterface;
use LTS\PhpXq\Jq\Runtime\PathOps;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\Values;
use stdClass;

/**
 * Compiles and runs jq programs with the real lexer, parser and compiler and a handful of test builtins, so
 * that the evaluator is tested without depending on the builtins worker's code.
 */
final class ProgramHarness
{
    private const string PRELUDE = <<<'JQ'
        def map(f): [.[] | f];
        def recurse(f): def r: ., (f | r); r;
        def to_entries: [keys[] as $k | {key: $k, value: .[$k]}];
        def from_entries: reduce .[] as $x ({}; . + {($x.key): $x.value});
        def with_entries(f): to_entries | map(f) | from_entries;
        def del(f): delpaths([path(f)]);
        def paths: path(..) | select(length > 0);
        def until(cond; update): def _until: if cond then . else (update | _until) end; _until;
        def isempty(g): label $go | (g | false, break $go), true;
        def join($x): reduce .[] as $i (null; (if . == null then "" else . + $x end) + ($i | if . == null then "" elif type == "string" then . else tojson end)) // "";
        JQ;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $globals
     * @param list<string>         $libraryPaths
     *
     * @return list<string> the compact JSON of every output
     *
     * @throws JqException
     * @throws JqCompileException
     */
    public static function outputs(string $program, string $input = 'null', array $globals = [], array $libraryPaths = []): array
    {
        $decoder = new JsonDecoder();
        $encoder = new JsonEncoder();
        $parser  = new Parser(new Lexer());
        $program = new Compiler(self::registry(), $parser, new FileModuleLoader($libraryPaths, $parser, $decoder))
            ->compile($parser->parse($program), array_keys($globals))
        ;

        $outputs = [];
        foreach ($decoder->decodeAll($input) as $value) {
            $program->run(new StubContext($globals, $libraryPaths), $value, static function (mixed $output) use ($encoder, &$outputs): void {
                $outputs[] = $encoder->encode($output, EncodeOptions::compact());
            });
        }

        return $outputs;
    }

    /**
     * The message of the jq error a program raises at run time.
     */
    public static function error(string $program, string $input = 'null'): string
    {
        try {
            self::outputs($program, $input);
        } catch (JqException $jqException) {
            return \is_string($jqException->value) ? $jqException->value : new JsonEncoder()->encode($jqException->value, EncodeOptions::compact());
        }

        return '(no error)';
    }

    /**
     * The message of the compile error a program raises.
     */
    public static function compileError(string $program, ?ModuleLoaderInterface $loader = null): string
    {
        $parser = new Parser(new Lexer());
        try {
            new Compiler(self::registry(), $parser, $loader ?? new FileModuleLoader([], $parser, new JsonDecoder()))
                ->compile($parser->parse($program))
            ;
        } catch (JqCompileException $jqCompileException) {
            return $jqCompileException->getMessage();
        }

        return '(no error)';
    }

    public static function registry(): BuiltinRegistryInterface
    {
        $encoder  = new JsonEncoder();
        $registry = new DefaultBuiltinRegistry();
        $value    = static function (string $name, int $arity, Closure $callback) use ($registry): void {
            $registry->register(new CallbackValueBuiltin($name, $arity, $callback));
        };
        $stream = static function (string $name, int $arity, Closure $callback) use ($registry): void {
            $registry->register(new CallbackStreamBuiltin($name, $arity, $callback));
        };

        $value('length', 0, static fn (mixed $input): mixed => match (true) {
            \is_string($input)           => \strlen($input),
            \is_array($input)            => \count($input),
            $input instanceof JsonObject => \count($input),
            null === $input              => 0,
            default                      => $input,
        });
        $value('keys', 0, static fn (mixed $input): mixed => $input instanceof JsonObject ? $input->sortedKeys() : array_keys((array)$input));
        $value('tostring', 0, static fn (mixed $input): string => \is_string($input) ? $input : $encoder->encode($input, EncodeOptions::compact()));
        $value('tojson', 0, static fn (mixed $input): string => $encoder->encode($input, EncodeOptions::compact()));
        $value('type', 0, static fn (mixed $input): string => Values::typeName($input));
        $value('floor', 0, static fn (mixed $input): int => \is_int($input) || \is_float($input) ? (int)floor($input) : 0);
        $value('infinite', 0, static fn (): float => \INF);
        $value('nan', 0, static fn (): float => \NAN);
        $value('reverse', 0, static fn (mixed $input): array => array_reverse((array)$input));
        $value('setpath', 2, static fn (mixed $input, mixed $path, mixed $new): mixed => PathOps::setPath($input, array_values((array)$path), $new));
        $value('delpaths', 1, static fn (mixed $input, mixed $paths): mixed => PathOps::deletePaths($input, array_values(array_map(static fn (mixed $path): array => array_values((array)$path), (array)$paths))));
        $value('add', 0, static function (mixed $input): mixed {
            $total = null;
            foreach ($input instanceof JsonObject ? $input->values() : (array)$input as $element) {
                $total = Arithmetic::add($total, $element);
            }

            return $total;
        });
        $value('format', 1, static fn (mixed $input, mixed $name): string => match ($name) {
            'base64' => base64_encode(\is_string($input) ? $input : $encoder->encode($input, EncodeOptions::compact())),
            'json'   => $encoder->encode($input, EncodeOptions::compact()),
            default  => \is_string($input) ? $input : $encoder->encode($input, EncodeOptions::compact()),
        });
        $value('pair', 2, static fn (mixed $input, mixed $first, mixed $second): array => [$first, $second]);
        /** @param list<FilterInterface> $filters */
        $stream('range', 1, static function (mixed $input, array $filters, Closure $emit): void {
            self::filter($filters, 0)->run($input, static function (mixed $limit) use ($emit): void {
                for ($i = 0; \is_int($limit) && $i < $limit; ++$i) {
                    $emit($i);
                }
            });
        });
        /** @param list<FilterInterface> $filters */
        $stream('range', 2, static function (mixed $input, array $filters, Closure $emit): void {
            self::filter($filters, 0)->run($input, static function (mixed $from) use ($input, $filters, $emit): void {
                self::filter($filters, 1)->run($input, static function (mixed $to) use ($from, $emit): void {
                    if (!\is_int($from) || !\is_int($to)) {
                        return;
                    }

                    for ($i = $from; $i < $to; ++$i) {
                        $emit($i);
                    }
                });
            });
        });
        /** @param list<FilterInterface> $filters */
        $stream('first', 1, static function (mixed $input, array $filters, Closure $emit): void {
            $token = new stdClass();
            try {
                self::filter($filters, 0)->run($input, static function (mixed $output) use ($emit, $token): never {
                    $emit($output);

                    throw new BreakException($token);
                });
            } catch (BreakException $breakException) {
                if ($breakException->label !== $token) {
                    throw $breakException;
                }
            }
        });
        $registry->register(new CallbackPathStreamBuiltin('extra', 0));
        $registry->addPrelude(self::PRELUDE);

        return $registry;
    }

    /**
     * @param array<mixed> $filters
     */
    private static function filter(array $filters, int $index): FilterInterface
    {
        $filter = $filters[$index] ?? null;
        if (!$filter instanceof FilterInterface) {
            throw new LogicException('Missing filter argument ' . $index);
        }

        return $filter;
    }
}
