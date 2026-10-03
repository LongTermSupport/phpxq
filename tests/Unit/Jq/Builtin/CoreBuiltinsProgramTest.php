<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin;

use LTS\PhpXq\Jq\Builtin\CoreBuiltins;
use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\Compiler;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\FileModuleLoader;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The core builtins and the jq prelude, driven through the real parser and evaluator.
 *
 * @internal
 */
final class CoreBuiltinsProgramTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('programs')]
    public function testProgram(string $program, string $input, array $expected): void
    {
        self::assertSame($expected, $this->evaluate($program, $input));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function programs(): iterable
    {
        yield 'map' => ['map(.+1)', '[1,2]', ['[2,3]']];

        yield 'select and values' => ['[.[] | select(. > 1)]', '[1,2,3]', ['[2,3]']];

        yield 'with_entries' => ['with_entries(.value += 1)', '{"a":1}', ['{"a":2}']];

        yield 'walk' => ['walk(if type == "number" then .+1 else . end)', '[1,[2]]', ['[2,[3]]']];

        yield 'sort_by and group_by' => ['group_by(.a) | map(length)', '[{"a":1},{"a":2},{"a":1}]', ['[2,1]']];

        yield 'limit' => ['[limit(2; range(10))]', 'null', ['[0,1]']];

        yield 'first and last' => ['[first(range(5)), last(range(5))]', 'null', ['[0,4]']];

        yield 'until' => ['0 | until(. > 3; .+1)', 'null', ['4']];

        yield 'paths and del' => ['del(.a) | [paths]', '{"a":1,"b":[1]}', ['[["b"],["b",0]]']];

        yield 'format' => ['@base64', '"hi"', ['"aGk="']];

        yield 'csv' => ['@csv', '[1,"a"]', ['"1,\"a\""']];

        yield 'tostream and fromstream' => ['fromstream(tostream)', '{"a":[1,2]}', ['{"a":[1,2]}']];

        yield 'INDEX' => ['INDEX(.id)', '[{"id":1}]', ['{"1":{"id":1}}']];

        yield 'indices' => ['indices(1)', '[0,1,2,1]', ['[1,3]']];

        yield 'combinations' => ['[combinations]', '[[1,2],[3]]', ['[[1,3],[2,3]]']];

        yield 'pick' => ['pick(.a)', '{"a":1,"b":2}', ['{"a":1}']];

        yield 'builtins includes prelude names' => ['builtins | index("walk/1") != null', 'null', ['true']];
    }

    public function testErrorsKeepTheirJqText(): void
    {
        try {
            $this->evaluate('tonumber', '"x"');
            self::fail('no error raised');
        } catch (JqException $jqException) {
            self::assertIsString($jqException->value);
            self::assertStringContainsString('cannot be parsed as a number', $jqException->value);
        }
    }

    /**
     * @return list<string>
     *
     * @throws JqException
     */
    private function evaluate(string $program, string $input): array
    {
        $decoder  = new JsonDecoder();
        $encoder  = new JsonEncoder();
        $parser   = new Parser(new Lexer());
        $registry = new DefaultBuiltinRegistry();
        new CoreBuiltins()->registerInto($registry);

        $compiled = new Compiler($registry, $parser, new FileModuleLoader([], $parser, $decoder))
            ->compile($parser->parse($program))
        ;

        $outputs = [];
        foreach ($decoder->decodeAll($input) as $value) {
            $compiled->run(new StubContext([], []), $value, static function (mixed $output) use ($encoder, &$outputs): void {
                $outputs[] = $encoder->encode($output, EncodeOptions::compact());
            });
        }

        return $outputs;
    }
}
