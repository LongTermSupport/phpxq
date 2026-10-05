<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\Compiler;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\Eval\Core;
use LTS\PhpXq\Jq\Runtime\Eval\NodeCompiler;
use LTS\PhpXq\Jq\Runtime\FileModuleLoader;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\ProgramHarness;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\StubContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Core::class)]
#[CoversClass(NodeCompiler::class)]
final class CoreTest extends TestCase
{
    private string $modules;

    protected function setUp(): void
    {
        $this->modules = \dirname(__DIR__, 4) . '/Conformance/Jq/modules';
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('modulePrograms')]
    public function testModules(string $program, array $expected): void
    {
        $outputs = ProgramHarness::outputs($program, 'null', [], [$this->modules]);

        if ('get_search_list' === $program) {
            self::assertSame(['["' . $this->modules . '"]'], $outputs);

            return;
        }

        self::assertSame($expected, $outputs);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function modulePrograms(): iterable
    {
        yield 'import with alias'            => ['import "a" as foo; import "b" as bar; def fooa: foo::a; [fooa, bar::a, bar::b, foo::a]', ['["a","b","c","a"]']];
        yield 'module with its own imports'  => ['import "c" as foo; [foo::a, foo::c]', ['[0,"acmehbah"]']];
        yield 'include'                      => ['include "c"; [a, c]', ['[0,"acmehbah"]']];
        yield 'data import'                  => ['import "data" as $e; import "data" as $d; [$d[].this,$e[].that,$d::d[].this,$e::e[].that]|join(";")', ['"is a test;is too;is a test;is too"']];
        yield 'data import in a function'    => ['import "data" as $a; import "data" as $b; def f: {$a, $b}; f', ['{"a":[{"this":"is a test","that":"is too"}],"b":[{"this":"is a test","that":"is too"}]}']];
        yield 'last definition wins'         => ['include "shadow1"; e', ['2']];
        yield 'later include wins'           => ['include "shadow1"; include "shadow2"; e', ['3']];
        yield 'later import wins'            => ['import "shadow1" as f; import "shadow2" as f; import "shadow1" as e; [e::e, f::e]', ['[2,3]']];
        yield 'same alias merges modules'    => ['import "test_bind_order" as check; check::check', ['true']];
        yield 'own definitions shadow includes' => ['include "shadow2"; def e: 9; e', ['9']];
        yield 'search list'                  => ['get_search_list', []];
    }

    public function testModulemetaDescribesAModule(): void
    {
        $outputs = ProgramHarness::outputs('modulemeta', '"c"', [], [$this->modules]);

        self::assertSame(
            ['{"whatever":null,"deps":[{"as":"foo","is_data":false,"relpath":"a"},{"search":"./","as":"d","is_data":false,"relpath":"d"},{"search":"./","as":"d2","is_data":false,"relpath":"d"},{"search":"./../lib/jq","as":"e","is_data":false,"relpath":"e"},{"search":"./../lib/jq","as":"f","is_data":false,"relpath":"f"},{"as":"d","is_data":true,"relpath":"data"}],"defs":["a/0","c/0"]}'],
            $outputs,
        );
    }

    #[DataProvider('brokenImports')]
    public function testImportErrorsAreCompileErrors(string $program): void
    {
        $parser = new Parser(new Lexer());

        $this->expectException(JqCompileException::class);

        new Compiler(ProgramHarness::registry(), $parser, new FileModuleLoader([$this->modules], $parser, new JsonDecoder()))
            ->compile($parser->parse($program))
        ;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenImports(): iterable
    {
        yield 'cycle through two modules' => ['import "cycle_a" as a; .'];
        yield 'module importing itself'   => ['import "cycle_self" as s; .'];
        yield 'missing module'            => ['import "nonexistent" as n; .'];
        yield 'missing data file'         => ['import "nonexistent" as $n; .'];
        yield 'syntax error in module'    => ['import "syntaxerror" as e; .'];
        yield 'undefined module function' => ['import "a" as foo; foo::nonexistent'];
        yield 'unknown alias'             => ['nope::a'];
    }

    public function testModulesAreCompiledOncePerCompiler(): void
    {
        $parser   = new Parser(new Lexer());
        $compiler = new Compiler(ProgramHarness::registry(), $parser, new FileModuleLoader([$this->modules], $parser, new JsonDecoder()));

        $first  = $compiler->compile($parser->parse('import "a" as a; a::a'));
        $second = $compiler->compile($parser->parse('import "a" as other; other::a'));

        $seen = [];
        foreach ([$first, $second] as $program) {
            $program->run(new StubContext(), null, static function (mixed $value) use (&$seen): void {
                $seen[] = $value;
            });
        }

        self::assertSame(['a', 'a'], $seen);
    }

    public function testEnvAndProgArgsAreAlwaysDefined(): void
    {
        self::assertSame(['"object"'], ProgramHarness::outputs('$ENV | type'));
        self::assertSame(['[]'], ProgramHarness::outputs('$__prog_args'));
    }

    public function testUnknownVariablesAreCompileErrors(): void
    {
        self::assertSame('$missing is not defined at <top-level>, line 1:', ProgramHarness::compileError('$missing'));
    }

    public function testPreludeDefinitionsSeeEarlierOnesAndThemselves(): void
    {
        $registry = new DefaultBuiltinRegistry();
        $registry->addPrelude('def one: 1; def two: one + one; def count(n): if n > 0 then n, count(n - 1) else empty end;');

        $parser  = new Parser(new Lexer());
        $program = new Compiler($registry, $parser, new FileModuleLoader([], $parser, new JsonDecoder()))
            ->compile($parser->parse('[two, count(3)]'))
        ;

        $seen = [];
        $program->run(new StubContext(), null, static function (mixed $value) use (&$seen): void {
            $seen[] = $value;
        });

        self::assertSame([[2, 3, 2, 1]], $seen);
    }

    public function testPreludeDefinitionsInOneChunkCannotCallLaterOnes(): void
    {
        $registry = new DefaultBuiltinRegistry();
        $registry->addPrelude('def first: second; def second: 1;');

        $parser = new Parser(new Lexer());

        try {
            new Compiler($registry, $parser, new FileModuleLoader([], $parser, new JsonDecoder()))
                ->compile($parser->parse('first'))
            ;
            self::fail('expected a compile error');
        } catch (JqCompileException $jqCompileException) {
            self::assertSame('second/0 is not defined at <top-level>, line 1:', $jqCompileException->getMessage());
        }
    }

    public function testPreludeKeepsLoadingChunksAfterAMultiDefinitionChunk(): void
    {
        $registry = new DefaultBuiltinRegistry();
        $registry->addPrelude("def a: 1; def b: 2;\ndef c: 3;");

        $parser  = new Parser(new Lexer());
        $program = new Compiler($registry, $parser, new FileModuleLoader([], $parser, new JsonDecoder()))
            ->compile($parser->parse('[a, b, c]'))
        ;

        $seen = [];
        $program->run(new StubContext(), null, static function (mixed $value) use (&$seen): void {
            $seen[] = $value;
        });

        self::assertSame([[1, 2, 3]], $seen);
    }

    public function testBreakOutsideAnyLabelIsACompileError(): void
    {
        self::assertSame('$*label-out is not defined at <top-level>, line 1:', ProgramHarness::compileError('break $out'));
    }

    public function testPreludeDefinitionsKeepTheIntrinsicsInOneChunk(): void
    {
        $registry = new DefaultBuiltinRegistry();
        $registry->addPrelude('def not: 99; def other: 1;');

        $parser  = new Parser(new Lexer());
        $program = new Compiler($registry, $parser, new FileModuleLoader([], $parser, new JsonDecoder()))
            ->compile($parser->parse('[(true | not), other]'))
        ;

        $seen = [];
        $program->run(new StubContext(), null, static function (mixed $value) use (&$seen): void {
            $seen[] = $value;
        });

        self::assertSame([[false, 1]], $seen);
    }

    public function testPreludeIsNotCompiledUntilUsed(): void
    {
        $registry = new DefaultBuiltinRegistry();
        // would fail to compile if it were compiled eagerly
        $registry->addPrelude('def broken: undefined_function; def fine: 1;');

        $parser = new Parser(new Lexer());

        $program = new Compiler($registry, $parser, new FileModuleLoader([], $parser, new JsonDecoder()))
            ->compile($parser->parse('fine'))
        ;
        $seen = [];
        $program->run(new StubContext(), null, static function (mixed $value) use (&$seen): void {
            $seen[] = $value;
        });

        self::assertSame([1], $seen);
    }

    public function testPreludeDefinitionsOnTheirOwnLinesAreParsedOnlyWhenUsed(): void
    {
        $registry = new DefaultBuiltinRegistry();
        // one definition per line, continuation lines indented: split without parsing; the first is not valid jq
        $registry->addPrelude(implode("\n", ['def broken: ((;', 'def two($x; f):', '  $x | f;', 'def fine: 1;']));

        $parser = new Parser(new Lexer());

        $program = new Compiler($registry, $parser, new FileModuleLoader([], $parser, new JsonDecoder()))
            ->compile($parser->parse('[fine, two(5; . + 1)]'))
        ;
        $seen = [];
        $program->run(new StubContext(), null, static function (mixed $value) use (&$seen): void {
            $seen[] = $value;
        });

        self::assertSame([[1, 6]], $seen);
    }

    public function testExpandTurnsValueParametersIntoBindings(): void
    {
        $parser = new Parser(new Lexer());
        $def    = $parser->parse('def f(g; $a; $b): g;')->defs[0];

        [$names, $body] = Core::expand($def);

        self::assertSame(['g', 'a', 'b'], $names);
        self::assertInstanceOf(\LTS\PhpXq\Jq\Ast\Bind::class, $body);
        self::assertInstanceOf(\LTS\PhpXq\Jq\Ast\Bind::class, $body->body);
    }
}
