<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Parser;

use LTS\PhpXq\Jq\Ast\ImportKindEnum;
use LTS\PhpXq\Jq\Parser\Lexer;
use LTS\PhpXq\Jq\Parser\Parser;
use LTS\PhpXq\Jq\Runtime\JqCompileException;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ParserTest extends TestCase
{
    #[DataProvider('provideExpressions')]
    #[DataProvider('provideOperators')]
    #[DataProvider('provideForms')]
    #[DataProvider('provideObjects')]
    public function testParsesExpression(string $source, string $expected): void
    {
        $program = $this->parser()->parse($source);

        self::assertNotNull($program->body);
        self::assertSame($expected, ParserAstDumper::program($program));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideExpressions(): iterable
    {
        yield 'identity' => ['.', '.'];
        yield 'recurse' => ['..', 'recurse'];
        yield 'field' => ['.foo', '(idx . "foo")'];
        yield 'keyword field' => ['.and', '(idx . "and")'];
        yield 'quoted field' => ['."a b"', '(idx . "a b")'];
        yield 'field chain' => ['.a.b.c', '(idx (idx (idx . "a") "b") "c")'];
        yield 'dot string chain' => ['.a."b"', '(idx (idx . "a") "b")'];
        yield 'dot bracket chain' => ['.a.[0]', '(idx (idx . "a") 0)'];
        yield 'index' => ['.[1]', '(idx . 1)'];
        yield 'index expr' => ['.["a"]', '(idx . "a")'];
        yield 'iterate' => ['.[]', '(iter .)'];
        yield 'slice' => ['.[1:2]', '(slice . 1 2)'];
        yield 'slice from' => ['.[1:]', '(slice . 1 _)'];
        yield 'slice to' => ['.[:2]', '(slice . _ 2)'];
        yield 'slice of expr' => ['.[.a:.b+1]', '(slice . (idx . "a") (+ (idx . "b") 1))'];
        yield 'optional' => ['.a?', '(try (idx . "a"))'];
        yield 'optional chain' => ['.a?.b[]?', '(try (iter (idx (try (idx . "a")) "b")))'];
        yield 'optional iterate' => ['.[]?', '(try (iter .))'];
        yield 'recurse optional' => ['..?', '(try recurse)'];
        yield 'double optional' => ['.a??', '(try (try (idx . "a")))'];
        yield 'recurse field' => ['..|.a', '(| recurse (idx . "a"))'];
        yield 'number' => ['1.5e3', '1.5e3'];
        yield 'leading dot number' => ['.5', '.5'];
        yield 'true' => ['true', 'true'];
        yield 'false' => ['false', 'false'];
        yield 'null' => ['null', 'null'];
        yield 'variable' => ['$x', '$x'];
        yield 'env variable' => ['$ENV', '$ENV'];
        yield 'loc' => ["\n\$__loc__", '$__loc__:2'];
        yield 'string' => ['"a\nb"', '"a\nb"'];
        yield 'empty string' => ['""', '""'];
        yield 'interpolation' => ['"a\(1)b"', '(interp - "a" 1 "b")'];
        yield 'nested interpolation' => ['"a\("b\(.c)")"', '(interp - "a" (interp - "b" (idx . "c")))'];
        yield 'format string' => ['@base64 "x\(.a)"', '(interp base64 "x" (idx . "a"))'];
        yield 'format string without interpolation' => ['@base64 "x"', '"x"'];
        yield 'bare format' => ['@csv', '@csv'];
        yield 'format then pipe' => ['@csv|.', '(| @csv .)'];
        yield 'paren' => ['(1)', '1'];
        yield 'empty array' => ['[]', '[]'];
        yield 'array' => ['[1,2]', '[(, 1 2)]'];
        yield 'array pipe' => ['[.[]|.a]', '[(| (iter .) (idx . "a"))]'];
        yield 'call' => ['length', 'length'];
        yield 'call args' => ['f(1;2)', '(f 1 2)'];
        yield 'call pipe arg' => ['f(.|.a)', '(f (| . (idx . "a")))'];
        yield 'namespaced call' => ['a::b(1)', '(a::b 1)'];
        yield 'call postfix' => ['f(1).a', '(idx (f 1) "a")'];
        yield 'call with true name' => ['true(1)', '(true 1)'];
        yield 'break' => ['break $out', '(break out)'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideOperators(): iterable
    {
        yield 'pipe' => ['.|.a', '(| . (idx . "a"))'];
        yield 'pipe right assoc' => ['1|2|3', '(| 1 (| 2 3))'];
        yield 'comma' => ['1,2,3', '(, (, 1 2) 3)'];
        yield 'comma binds tighter than pipe' => ['1,2|3', '(| (, 1 2) 3)'];
        yield 'alt right assoc' => ['1//2//3', '(// 1 (// 2 3))'];
        yield 'alt tighter than comma' => ['1,2//3', '(, 1 (// 2 3))'];
        yield 'assign looser than or' => ['.a = 1 or 2', '(= (idx . "a") (or 1 2))'];
        yield 'assign looser than alt rhs' => ['.a = 1 // 2', '(// (= (idx . "a") 1) 2)'];
        yield 'alt rhs may assign' => ['1 // .a = 2', '(// 1 (= (idx . "a") 2))'];
        yield 'update' => ['.a |= .+1', '(|= (idx . "a") (+ . 1))'];
        yield 'plus assign' => ['.a += 1', '(+= (idx . "a") 1)'];
        yield 'minus assign' => ['.a -= 1', '(-= (idx . "a") 1)'];
        yield 'mul assign' => ['.a *= 1', '(*= (idx . "a") 1)'];
        yield 'div assign' => ['.a /= 1', '(/= (idx . "a") 1)'];
        yield 'mod assign' => ['.a %= 1', '(%= (idx . "a") 1)'];
        yield 'alt assign' => ['.a //= 1', '(//= (idx . "a") 1)'];
        yield 'or and' => ['1 or 2 and 3', '(or 1 (and 2 3))'];
        yield 'and left assoc' => ['1 and 2 and 3', '(and (and 1 2) 3)'];
        yield 'comparison' => ['1 == 2', '(== 1 2)'];
        yield 'comparison looser than plus' => ['1 + 2 < 3', '(< (+ 1 2) 3)'];
        yield 'all comparisons' => ['1 != 2 and 1 <= 2 and 1 >= 2 and 1 > 2', '(and (and (and (!= 1 2) (<= 1 2)) (>= 1 2)) (> 1 2))'];
        yield 'plus minus left assoc' => ['1 - 2 + 3', '(+ (- 1 2) 3)'];
        yield 'mul tighter than plus' => ['1 + 2 * 3', '(+ 1 (* 2 3))'];
        yield 'div mod' => ['1 / 2 % 3', '(% (/ 1 2) 3)'];
        yield 'negate' => ['-1', '(neg 1)'];
        yield 'negate plus' => ['-1 + 2', '(+ (neg 1) 2)'];
        yield 'negate field' => ['-.a', '(neg (idx . "a"))'];
        yield 'subtract negative' => ['1 - -2', '(- 1 (neg 2))'];
        yield 'optional binds tighter' => ['1 + .a?', '(+ 1 (try (idx . "a")))'];
        yield 'as binds the whole binary expression' => ['1 + . as $x | $x', '(as (+ 1 .) ($x) $x)'];
        yield 'as binds a negated term' => ['-1 as $x | $x', '(as (neg 1) ($x) $x)'];
        yield 'object value negation ends at pipe' => ['{x: -. | abs}', '{("x" (| (neg .) abs))}'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideForms(): iterable
    {
        yield 'if else' => ['if . then 1 else 2 end', '(if . 1 2)'];
        yield 'if no else' => ['if . then 1 end', '(if . 1 _)'];
        yield 'elif' => ['if 1 then 2 elif 3 then 4 else 5 end', '(if 1 2 (if 3 4 5))'];
        yield 'elif no else' => ['if 1 then 2 elif 3 then 4 end', '(if 1 2 (if 3 4 _))'];
        yield 'if body pipe' => ['if . then .a|.b else 2 end', '(if . (| (idx . "a") (idx . "b")) 2)'];
        yield 'if postfix' => ['if . then {a:1} else {a:2} end.a', '(idx (if . {("a" 1)} {("a" 2)}) "a")'];
        yield 'try' => ['try .a', '(try (idx . "a"))'];
        yield 'try catch' => ['try .a catch .b', '(try (idx . "a") (idx . "b"))'];
        yield 'try binds tight' => ['try 1 + 2', '(+ (try 1) 2)'];
        yield 'try error call' => ['try error("x") catch .', '(try (error "x") .)'];
        yield 'try try' => ['try try 1', '(try (try 1))'];
        yield 'reduce' => ['reduce .[] as $x (0; . + $x)', '(reduce (iter .) $x 0 (+ . $x))'];
        yield 'reduce operator source' => ['reduce .[] / .[] as $i (0; . + $i)', '(reduce (/ (iter .) (iter .)) $i 0 (+ . $i))'];
        yield 'foreach negated source' => ['foreach -.[] as $x (0; .)', '(foreach (neg (iter .)) $x 0 . _)'];
        yield 'reduce source with parenthesised binding' => ['reduce (. as $y | $y) as $x (0; .)', '(reduce (as . ($y) $y) $x 0 .)'];
        yield 'reduce destructure' => ['reduce .[] as [$a,$b] (0; .)', '(reduce (iter .) [$a $b] 0 .)'];
        yield 'foreach 2' => ['foreach .[] as $x (0; .+$x)', '(foreach (iter .) $x 0 (+ . $x) _)'];
        yield 'foreach 3' => ['foreach .[] as $x (0; .+$x; [$x,.])', '(foreach (iter .) $x 0 (+ . $x) [(, $x .)])'];
        yield 'reduce then pipe' => ['reduce .[] as $x (0; .+$x) | . * 2', '(| (reduce (iter .) $x 0 (+ . $x)) (* . 2))'];
        yield 'label' => ['label $out | 1, break $out', '(label out (, 1 (break out)))'];
        yield 'bind' => ['. as $x | $x', '(as . ($x) $x)'];
        yield 'bind postfix source' => ['.a[] as $x | $x, 1', '(as (iter (idx . "a")) ($x) (, $x 1))'];
        yield 'bind array' => ['. as [$a, [$b]] | $a', '(as . ([$a [$b]]) $a)'];
        yield 'bind object' => ['. as {a: $x, $y, "b": [$z], (1,2): $w, if: $v, $o: [$p]} | 1', '(as . ({(_ "a" $x) ($y _ _) (_ "b" [$z]) (_ (, 1 2) $w) (_ "if" $v) ($o _ [$p])}) 1)'];
        yield 'bind object interp key' => ['. as {"a\(1)": $x} | 1', '(as . ({(_ (interp - "a" 1) $x)}) 1)'];
        yield 'bind alternatives' => ['.[] as [$a] ?// $a | $a', '(as (iter .) ([$a] ?// $a) $a)'];
        yield 'bind body extends over comma and pipe' => ['. as $x | 1, 2 | 3', '(as . ($x) (| (, 1 2) 3))'];
        yield 'def scope' => ['def f: 1; f', '(def f() 1 f)'];
        yield 'def params' => ['def f(g; $x): g + $x; f(1;2)', '(def f(g $x) (+ g $x) (f 1 2))'];
        yield 'nested def' => ['def f: def g: 2; g; f', '(def f() (def g() 2 g) f)'];
        yield 'def in pipe' => ['1 | def f: 2; f | f', '(| 1 (def f() 2 (| f f)))'];
        yield 'def in binary' => ['1 + def f: 2; f', '(+ 1 (def f() 2 f))'];
        yield 'def body binds pipe' => ['def f: 1 | 2; f', '(def f() (| 1 2) f)'];
        yield 'label in binary' => ['1, label $x | 2', '(, 1 (label x 2))'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideObjects(): iterable
    {
        yield 'empty' => ['{}', '{}'];
        yield 'ident key' => ['{a: 1}', '{("a" 1)}'];
        yield 'shorthand' => ['{a}', '{("a" (idx . "a"))}'];
        yield 'variable shorthand' => ['{$x}', '{("x" $x)}'];
        yield 'loc shorthand' => ['{$__loc__}', '{("__loc__" $__loc__:1)}'];
        yield 'variable key' => ['{$x: 1}', '{($x 1)}'];
        yield 'string key' => ['{"a b": 1}', '{("a b" 1)}'];
        yield 'string shorthand' => ['{"a b"}', '{("a b" (idx . "a b"))}'];
        yield 'interpolated shorthand' => ['{"a\(1)"}', '{((interp - "a" 1) (idx . (interp - "a" 1)))}'];
        yield 'paren key' => ['{(.a): 1}', '{((idx . "a") 1)}'];
        yield 'format key' => ['{@base64 "x": 1}', '{("x" 1)}'];
        yield 'format interpolated key' => ['{@base64 "x\(1)": 1}', '{((interp base64 "x" 1) 1)}'];
        yield 'keyword key' => ['{if: 1, and: 2}', '{("if" 1) ("and" 2)}'];
        yield 'keyword shorthand' => ['{if}', '{("if" (idx . "if"))}'];
        yield 'many' => ['{a: 1, b: 2, c}', '{("a" 1) ("b" 2) ("c" (idx . "c"))}'];
        yield 'trailing comma' => ['{a: 1,}', '{("a" 1)}'];
        yield 'value pipe' => ['{a: 1 | 2}', '{("a" (| 1 2))}'];
        yield 'value negation' => ['{a: -1}', '{("a" (neg 1))}'];
        yield 'value postfix' => ['{a: .b.c[]}', '{("a" (iter (idx (idx . "b") "c")))}'];
        yield 'value nested object' => ['{a: {b: 1}}', '{("a" {("b" 1)})}'];
        yield 'value call' => ['{a: f(1)}', '{("a" (f 1))}'];
        yield 'value keyword form' => ['{a: if . then 1 else 2 end}', '{("a" (if . 1 2))}'];
        yield 'value comma ends' => ['{a: 1, b: 2}', '{("a" 1) ("b" 2)}'];
        yield 'value arithmetic' => ['{x: 1 + 2, y: false or true, z: null // 3}', '{("x" (+ 1 2)) ("y" (or false true)) ("z" (// null 3))}'];
        yield 'value arithmetic then pipe' => ['{a: 1 + 2 | 3}', '{("a" (| (+ 1 2) 3))}'];
        yield 'value parenthesised' => ['{a: (1 + 2)}', '{("a" (+ 1 2))}'];
        yield 'postfix on object' => ['{a:1}.a', '(idx {("a" 1)} "a")'];
        yield 'namespaced key' => ['{a::b: 1}', '{("a::b" 1)}'];
    }

    public function testEmptyProgramHasNoBody(): void
    {
        $program = $this->parser()->parse('');

        self::assertNull($program->body);
        self::assertSame([], $program->defs);
        self::assertSame([], $program->imports);
        self::assertNull($program->module);
    }

    public function testCommentOnlyProgramHasNoBody(): void
    {
        self::assertNull($this->parser()->parse("# nothing\n")->body);
    }

    public function testTopLevelDefsAreCollected(): void
    {
        $program = $this->parser()->parse('def f: 1; def g($a): $a; f + g(2)');

        self::assertCount(2, $program->defs);
        self::assertSame('f/0', $program->defs[0]->signature());
        self::assertSame(['$a'], $program->defs[1]->params);
        self::assertNotNull($program->body);
        self::assertSame('(+ f (g 2))', ParserAstDumper::dump($program->body));
    }

    public function testDefsOnlyProgramIsALibrary(): void
    {
        $program = $this->parser()->parse('def f: 1; def g: 2;');

        self::assertCount(2, $program->defs);
        self::assertNull($program->body);
    }

    public function testDefLinesAreRecorded(): void
    {
        $program = $this->parser()->parse("\n\ndef f: 1;");

        self::assertSame(3, $program->defs[0]->line);
    }

    public function testCallLinesAreRecorded(): void
    {
        $program = $this->parser()->parse("1 |\n  foo");

        self::assertNotNull($program->body);
        self::assertSame(
            '(| 1 foo)',
            ParserAstDumper::dump($program->body),
        );
        self::assertInstanceOf(\LTS\PhpXq\Jq\Ast\Pipe::class, $program->body);
        self::assertInstanceOf(\LTS\PhpXq\Jq\Ast\FunctionCall::class, $program->body->right);
        self::assertSame(2, $program->body->right->line);
    }

    public function testModuleDirective(): void
    {
        $program = $this->parser()->parse('module {name: "m", list: ["a", null, true, false]}; def f: 1;');

        self::assertNotNull($program->module);
        self::assertEquals(
            new JsonObject(['name' => 'm', 'list' => ['a', null, true, false]]),
            $program->module->metadata,
        );
        self::assertCount(1, $program->defs);
    }

    public function testImports(): void
    {
        $program = $this->parser()->parse(
            'import "a" as foo; import "b" as $data {search: "./"}; include "c"; include "d" {search: "x"}; .',
        );

        self::assertCount(4, $program->imports);
        [$a, $b, $c, $d] = $program->imports;
        self::assertSame(['a', 'foo', ImportKindEnum::Import, null], [$a->path, $a->alias, $a->kind, $a->metadata]);
        self::assertSame(['b', 'data', ImportKindEnum::Data], [$b->path, $b->alias, $b->kind]);
        self::assertEquals(new JsonObject(['search' => './']), $b->metadata);
        self::assertSame(['c', null, ImportKindEnum::Include, null], [$c->path, $c->alias, $c->kind, $c->metadata]);
        self::assertEquals(new JsonObject(['search' => 'x']), $d->metadata);
        self::assertNotNull($program->body);
    }

    public function testImportWithoutBodyIsAllowed(): void
    {
        $program = $this->parser()->parse('include "a";');

        self::assertCount(1, $program->imports);
        self::assertNull($program->body);
    }

    public function testNamespacedImportAliasIsPlainIdent(): void
    {
        $program = $this->parser()->parse('import "a" as foo; foo::bar');

        self::assertNotNull($program->body);
        self::assertSame('foo::bar', ParserAstDumper::dump($program->body));
    }

    /**
     * @param non-empty-string $messageStart
     */
    #[DataProvider('provideErrors')]
    public function testSyntaxErrors(string $source, string $messageStart): void
    {
        try {
            $this->parser()->parse($source);
            self::fail('expected a compile error for ' . $source);
        } catch (JqCompileException $jqCompileException) {
            self::assertStringStartsWith($messageStart, $jqCompileException->getMessage());
            self::assertStringContainsString(' at <top-level>, line ', $jqCompileException->getMessage());
            self::assertStringEndsWith(':', $jqCompileException->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideErrors(): iterable
    {
        yield 'trailing token' => ['. .', "syntax error, unexpected '.'"];
        yield 'unexpected closing paren' => ['(1))', "syntax error, unexpected ')'"];
        yield 'dangling operator' => ['1 +', 'syntax error, unexpected end of file'];
        yield 'dangling pipe' => ['1 |', 'syntax error, unexpected end of file'];
        yield 'leading pipe' => ['| 1', "syntax error, unexpected '|', expecting end of file"];
        yield 'two literals' => ['1 2', 'syntax error, unexpected LITERAL'];
        yield 'two idents' => ['a b', 'syntax error, unexpected IDENT'];
        yield 'nonassoc comparison' => ['1 == 2 == 3', 'syntax error, unexpected =='];
        yield 'nonassoc assignment' => ['.a = 1 = 2', "syntax error, unexpected '='"];
        yield 'nonassoc update' => ['.a |= 1 |= 2', 'syntax error, unexpected |='];
        yield 'object value comma operator chain' => ['{a: 1,, b: 2}', "syntax error, unexpected ','"];
        yield 'unterminated object' => ['{a: 1', 'syntax error, unexpected end of file'];
        yield 'unterminated array' => ['[1', 'syntax error, unexpected end of file'];
        yield 'if without then' => ['if 1 end', 'syntax error, unexpected end'];
        yield 'if without end' => ['if 1 then 2', 'syntax error, unexpected end of file'];
        yield 'else without if' => ['else', 'syntax error, unexpected else'];
        yield 'empty array pattern' => ['. as [] | null', "syntax error, unexpected ']', expecting BINDING or '[' or '{'"];
        yield 'empty object pattern' => ['. as {} | null', "syntax error, unexpected '}'"];
        yield 'as without pipe' => ['. as $x', 'syntax error, unexpected end of file'];
        yield 'reduce without parens' => ['reduce . as $x 1', 'syntax error, unexpected LITERAL'];
        yield 'def without semicolon' => ['def f: 1', 'syntax error, unexpected end of file'];
        yield 'def bad param' => ['def f(1): 1; f', 'syntax error, unexpected LITERAL'];
        yield 'break without label' => ['break', 'syntax error, unexpected end of file'];
        yield 'bad object key' => ['{1+2:3}', 'May need parentheses around object key expression'];
        yield 'dot dot field' => ['..a', 'syntax error, unexpected IDENT'];
        yield 'module not constant' => ['module (.+1); 0', 'Module metadata must be constant'];
        yield 'module not object' => ['module []; 0', 'Module metadata must be an object'];
        yield 'include not constant' => ['include "a" (.+1); 0', 'Module metadata must be constant'];
        yield 'include not object' => ['include "a" []; 0', 'Module metadata must be an object'];
        yield 'import path not constant' => ['include "\(a)"; 0', 'Import path must be constant'];
        yield 'multiple reduce patterns' => ['reduce . as [$a] ?// $a (0; .)', 'syntax error, unexpected ?//'];
        yield 'bare format in object key' => ['{@base64: 1}', "syntax error, unexpected ':'"];
    }

    public function testErrorReportsLineAndColumn(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessageMatches('/^syntax error, unexpected \']\', expecting BINDING or \'\[\' or \'\{\' at <top-level>, line 1, column 7:$/');

        $this->parser()->parse('. as [] | null');
    }

    public function testErrorOnLaterLine(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessageMatches('/unexpected IDENT at <top-level>, line 2, column 5:$/');

        $this->parser()->parse("1 |\n  a b");
    }

    public function testUnterminatedIfAndTryAreAnnotated(): void
    {
        try {
            $this->parser()->parse("[\n  try if .\n         then 1\n         else 2\n  catch ]");
            self::fail('expected a compile error');
        } catch (JqCompileException $jqCompileException) {
            self::assertSame(
                "syntax error, unexpected catch, expecting end or '|' or ',' at <top-level>, line 5, column 3:"
                . "\njq: error: Possibly unterminated 'if' statement at <top-level>, line 2, column 7:"
                . "\njq: error: Possibly unterminated 'try' statement at <top-level>, line 2, column 3:",
                $jqCompileException->getMessage(),
            );
        }
    }

    public function testEndOfInputSitsOnTheFinalLineBreak(): void
    {
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessageMatches('/unexpected end of file at <top-level>, line 1, column 3:$/');

        $this->parser()->parse("if\n");
    }

    public function testTooManyParametersAreRejected(): void
    {
        $params = implode(';', array_map(static fn (int $i): string => 'a' . $i, range(1, 4096)));
        $this->expectException(JqCompileException::class);
        $this->expectExceptionMessageMatches('/^too many function parameters or local function definitions \(max 4095\)$/');

        $this->parser()->parse(\sprintf('def f(%s): .; .', $params));
    }

    public function testLexerErrorsPropagate(): void
    {
        $this->expectException(JqCompileException::class);

        $this->parser()->parse('"abc');
    }

    public function testParserIsReusable(): void
    {
        $parser  = $this->parser();
        $failed  = false;
        try {
            $parser->parse('1 +');
        } catch (JqCompileException) {
            $failed = true;
        }

        self::assertTrue($failed);
        $program = $parser->parse('2');

        self::assertNotNull($program->body);
        self::assertSame('2', ParserAstDumper::dump($program->body));
    }

    private function parser(): Parser
    {
        return new Parser(new Lexer());
    }
}
