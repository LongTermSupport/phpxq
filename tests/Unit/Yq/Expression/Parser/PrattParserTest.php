<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Expression\Parser;

use LTS\PhpXq\Tests\Unit\Yq\Expression\AstDumper;
use LTS\PhpXq\Yq\Expression\ExpressionLexer;
use LTS\PhpXq\Yq\Expression\ExpressionSyntaxException;
use LTS\PhpXq\Yq\Expression\Parser\PrattParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PrattParser::class)]
final class PrattParserTest extends TestCase
{
    #[DataProvider('parseProvider')]
    public function testParse(string $source, string $expected): void
    {
        $parser = new PrattParser(new ExpressionLexer()->tokenize($source));

        self::assertSame($expected, AstDumper::dump($parser->parseAll()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function parseProvider(): iterable
    {
        yield 'empty'                 => ['', '.'];
        yield 'comment only'          => ['# nothing', '.'];
        yield 'identity'              => ['.', '.'];
        yield 'field'                 => ['.a', '(field . !!str:a)'];
        yield 'nested'                => ['.a.b', '(field (field . !!str:a) !!str:b)'];
        yield 'numeric name'          => ['.a.0', '(field (field . !!str:a) !!str:0)'];
        yield 'quoted'                => ['."a b"', '(field . !!str:a b)'];
        yield 'quoted nested'         => ['.a."*a*"', '(field (field . !!str:a) !!str:*a*)'];
        yield 'bracket string'        => ['.["red rabbit"]', '(field . !!str:red rabbit)'];
        yield 'bracket index'         => ['.[0]', '(field . !!int:0)'];
        yield 'negative index'        => ['.[-1]', '(field . !!int:-1)'];
        yield 'bracket expression'    => ['.[.b]', '(field . (field . !!str:b))'];
        yield 'bracket union'         => ['.a[0, 2]', '(field (field . !!str:a) (, !!int:0 !!int:2))'];
        yield 'chained brackets'      => ['.[1][0]', '(field (field . !!int:1) !!int:0)'];
        yield 'bracket after name'    => ['.a["k.d"]["x.y"]', '(field (field (field . !!str:a) !!str:k.d) !!str:x.y)'];
        yield 'iterate'               => ['.[]', '(iter .)'];
        yield 'iterate field'         => ['.a[]', '(iter (field . !!str:a))'];
        yield 'iterate dotted'        => ['.a.[]', '(iter (field . !!str:a))'];
        yield 'dotted index'          => ['.a.b.[0]', '(field (field (field . !!str:a) !!str:b) !!int:0)'];
        yield 'slice'                 => ['.[1:3]', '(slice . !!int:1 !!int:3)'];
        yield 'slice open start'      => ['.[:2]', '(slice . _ !!int:2)'];
        yield 'slice open end'        => ['.[2:]', '(slice . !!int:2 _)'];
        yield 'slice negative'        => ['.[1:-1]', '(slice . !!int:1 !!int:-1)'];
        yield 'slice negative start'  => ['.country[-5:]', '(slice (field . !!str:country) !!int:-5 _)'];
        yield 'slice variable'        => ['.[$pos:]', '(slice . $pos _)'];
        yield 'slice parens'          => ['.[0:($pos)]', '(slice . !!int:0 $pos)'];
        yield 'optional field'        => ['.a?', '(field? . !!str:a)'];
        yield 'optional iterate'      => ['.a[]?', '(iter? (field . !!str:a))'];
        yield 'optional slice'        => ['.[1:2]?', '(slice? . !!int:1 !!int:2)'];
        yield 'recursive'             => ['..', '(rec .)'];
        yield 'recursive keys'        => ['...', '(recall .)'];
        yield 'recursive pipe'        => ['.. | tag', '(| (rec .) (call tag))'];
        yield 'glob'                  => ['.a*', '(field . !!str:a*)'];
        yield 'variable field'        => ['$names[.author]', '(field $names (field . !!str:author))'];
        yield 'variable dotted'       => ['$ENV.PATH', '(field $ENV !!str:PATH)'];
        yield 'call postfix'          => ['select(.a).name', '(field (call select (field . !!str:a)) !!str:name)'];
        yield 'keyword field names'   => ['.if.then.else.end', '(field (field (field (field . !!str:if) !!str:then) !!str:else) !!str:end)'];

        yield 'numbers'               => ['1', '!!int:1'];
        yield 'float'                 => ['1.5', '!!float:1.5'];
        yield 'hex'                   => ['0x1F', '!!int:0x1F'];
        yield 'string'                => ['"cat"', '!!str:cat'];
        yield 'single quoted'         => ["'cat'", '!!str:cat'];
        yield 'true'                  => ['true', '!!bool:true'];
        yield 'false'                 => ['false', '!!bool:false'];
        yield 'null'                  => ['null', '!!null:null'];
        yield 'tilde'                 => ['~', '!!null:~'];

        yield 'pipe'                  => ['.a | .b', '(| (field . !!str:a) (field . !!str:b))'];
        yield 'pipe left assoc'       => ['.a | .b | .c', '(| (| (field . !!str:a) (field . !!str:b)) (field . !!str:c))'];
        yield 'union'                 => ['.a, .b', '(, (field . !!str:a) (field . !!str:b))'];
        yield 'union binds tighter'   => ['.a, .b | .c', '(| (, (field . !!str:a) (field . !!str:b)) (field . !!str:c))'];
        yield 'assign vs union'       => ['.a = 1, .b = 2', '(, (= (field . !!str:a) !!int:1) (= (field . !!str:b) !!int:2))'];
        yield 'assign then pipe'      => ['.a = "cat" | .b = "dog"', '(| (= (field . !!str:a) !!str:cat) (= (field . !!str:b) !!str:dog))'];
        yield 'assign right assoc'    => ['.a = .b = 1', '(= (field . !!str:a) (= (field . !!str:b) !!int:1))'];
        yield 'update'                => ['.a |= . + 1', '(|= (field . !!str:a) (+ . !!int:1))'];
        yield 'add assign'            => ['.a += .b', '(+= (field . !!str:a) (field . !!str:b))'];
        yield 'subtract assign'       => ['.a -= 1', '(-= (field . !!str:a) !!int:1)'];
        yield 'multiply assign'       => ['.a *= .b', '(*= (field . !!str:a) (field . !!str:b))'];
        yield 'multiply assign c'     => ['.a *=c .b', '(*=/c (field . !!str:a) (field . !!str:b))'];
        yield 'divide assign'         => ['.a /= 2', '(/= (field . !!str:a) !!int:2)'];
        yield 'modulo assign'         => ['.a %= 2', '(%= (field . !!str:a) !!int:2)'];
        yield 'assign clobber'        => ['.a =c .b', '(=/c (field . !!str:a) (field . !!str:b))'];
        yield 'alternative'           => ['.a // "x"', '(// (field . !!str:a) !!str:x)'];
        yield 'alternative in assign' => ['.a = .b // 1', '(= (field . !!str:a) (// (field . !!str:b) !!int:1))'];
        yield 'or and'                => ['.a or .b and .c', '(or (field . !!str:a) (and (field . !!str:b) (field . !!str:c)))'];
        yield 'comparison'            => ['.a == 1 and .b != 2', '(and (== (field . !!str:a) !!int:1) (!= (field . !!str:b) !!int:2))'];
        yield 'relational'            => ['.a < 1', '(< (field . !!str:a) !!int:1)'];
        yield 'relational equal'      => ['.a <= 1', '(<= (field . !!str:a) !!int:1)'];
        yield 'greater'               => ['.a > .b', '(> (field . !!str:a) (field . !!str:b))'];
        yield 'greater equal'         => ['.a >= .b', '(>= (field . !!str:a) (field . !!str:b))'];
        yield 'arithmetic'            => ['1 + 2 * 3 - 4', '(- (+ !!int:1 (* !!int:2 !!int:3)) !!int:4)'];
        yield 'divide modulo'         => ['.a / .b % 2', '(% (/ (field . !!str:a) (field . !!str:b)) !!int:2)'];
        yield 'compare below add'     => ['.a + 1 == 3', '(== (+ (field . !!str:a) !!int:1) !!int:3)'];
        yield 'subtract identity'     => ['. - [1]', '(- . (collect !!int:1))'];
        yield 'multiply plus'         => ['.a *+ .b', '(*/+ (field . !!str:a) (field . !!str:b))'];
        yield 'multiply existing'     => ['.a *? .b', '(*/? (field . !!str:a) (field . !!str:b))'];
        yield 'multiply deep'         => ['.a *d .b', '(*/d (field . !!str:a) (field . !!str:b))'];
        yield 'multiply no new'       => ['.a *n .b', '(*/n (field . !!str:a) (field . !!str:b))'];
        yield 'multiply combined'     => ['.a *?+ .b', '(*/?+ (field . !!str:a) (field . !!str:b))'];
        yield 'multiply plain'        => ['4 * .b', '(* !!int:4 (field . !!str:b))'];
        yield 'parens regroup'        => ['(.a, .b) = "x"', '(= (, (field . !!str:a) (field . !!str:b)) !!str:x)'];
        yield 'paren precedence'      => ['(1 + 2) * 3', '(* (+ !!int:1 !!int:2) !!int:3)'];
        yield 'paren assign'          => ['(.a // (.a = 0)) += 1', '(+= (// (field . !!str:a) (= (field . !!str:a) !!int:0)) !!int:1)'];

        yield 'style juxtaposition'   => ['.a style="double"', '(= (| (field . !!str:a) (call style)) !!str:double)'];
        yield 'recursive style'       => ['.. style="flow"', '(= (| (rec .) (call style)) !!str:flow)'];
        yield 'recursive update'      => ['.. line_comment |= .', '(|= (| (rec .) (call line_comment)) .)'];
        yield 'keys style'           => ['... style=""', '(= (| (recall .) (call style)) !!str:)'];
        yield 'identity comment'      => ['. foot_comment=.a', '(= (| . (call foot_comment)) (field . !!str:a))'];
        yield 'paren comment'         => ['(.a | key) head_comment="single"', '(= (| (| (field . !!str:a) (call key)) (call head_comment)) !!str:single)'];
        yield 'tag no space'          => ['(.. | select(tag == "!!int")) tag= "!!str"', '(= (| (| (rec .) (call select (== (call tag) !!str:!!int))) (call tag)) !!str:!!str)'];
        yield 'alias assign'          => ['.a alias = "meow"', '(= (| (field . !!str:a) (call alias)) !!str:meow)'];
        yield 'anchor update'         => ['.a anchor |= .b', '(|= (| (field . !!str:a) (call anchor)) (field . !!str:b))'];
        yield 'iterate style update'  => ['.[] style |= .', '(|= (| (iter .) (call style)) .)'];
        yield 'comments juxtapose'    => ['... comments=""', '(= (| (recall .) (call comments)) !!str:)'];

        yield 'call no args'          => ['length', '(call length)'];
        yield 'call'                  => ['select(.a)', '(call select (field . !!str:a))'];
        yield 'call two args'         => ['with(.a ; . = 1)', '(call with (field . !!str:a) (= . !!int:1))'];
        yield 'call comma in arg'     => ['with_dtf("f", .a += 1)', '(call with_dtf (, !!str:f (+= (field . !!str:a) !!int:1)))'];
        yield 'call empty parens'     => ['now()', '(call now)'];
        yield 'call negative arg'     => ['parent(-1)', '(call parent !!int:-1)'];
        yield 'format'                => ['.a | @base64', '(| (field . !!str:a) (call @base64))'];
        yield 'format chain'          => ['@yaml | @base64', '(| (call @yaml) (call @base64))'];
        yield 'format assign'         => ['.a |= @csvd', '(|= (field . !!str:a) (call @csvd))'];
        yield 'env flag arg'          => ['envsubst(nu)', '(call envsubst (call nu))'];
        yield 'not'                   => ['"" | not', '(| !!str: (call not))'];
        yield 'and word'              => ['true and false', '(and !!bool:true !!bool:false)'];

        yield 'collect'               => ['[.a, .b]', '(collect (, (field . !!str:a) (field . !!str:b)))'];
        yield 'collect empty'         => ['[]', '(collect _)'];
        yield 'collect pipe'          => ['[.[] | .a]', '(collect (| (iter .) (field . !!str:a)))'];
        yield 'collect add'           => ['[1] + [2]', '(+ (collect !!int:1) (collect !!int:2))'];

        yield 'object empty'          => ['{}', '(obj)'];
        yield 'object'                => ['{"a": 1, "b": .c}', '(obj [!!str:a !!int:1] [!!str:b (field . !!str:c)])'];
        yield 'object bare key'       => ['{a: 1}', '(obj [!!str:a !!int:1])'];
        yield 'object shorthand'      => ['{a, b}', '(obj [!!str:a (field . !!str:a)] [!!str:b (field . !!str:b)])'];
        yield 'object string short'   => ['{"a b"}', '(obj [!!str:a b (field . !!str:a b)])'];
        yield 'object path key'       => ['{.name: .pets.[]}', '(obj [(field . !!str:name) (iter (field . !!str:pets))])'];
        yield 'object pipe value'     => ['{"cat": .a | to_xml(1)}', '(obj [!!str:cat (| (field . !!str:a) (call to_xml !!int:1))])'];
        yield 'object variable value' => ['{"t":.title, "a": $names[.author]}', '(obj [!!str:t (field . !!str:title)] [!!str:a (field $names (field . !!str:author))])'];
        yield 'object paren key'      => ['{(.k): 1}', '(obj [(field . !!str:k) !!int:1])'];
        yield 'object number key'     => ['{1: 2}', '(obj [!!int:1 !!int:2])'];
        yield 'object in pipe'        => ['{"match": ., "doc": document_index}', '(obj [!!str:match .] [!!str:doc (call document_index)])'];

        yield 'if'                    => ['if .b then "x" else "y" end', '(if (field . !!str:b) !!str:x !!str:y)'];
        yield 'if no else'            => ['if . == 2 then "two" end', '(if (== . !!int:2) !!str:two _)'];
        yield 'elif'                  => ['if . == 1 then "one" elif . == 2 then "two" else "many" end', '(if (== . !!int:1) !!str:one (if (== . !!int:2) !!str:two !!str:many))'];
        yield 'if union condition'    => ['if true, false then "yes" else "no" end', '(if (, !!bool:true !!bool:false) !!str:yes !!str:no)'];
        yield 'if inside assign'      => ['(if .enabled then .a else .b end) = 10', '(= (if (field . !!str:enabled) (field . !!str:a) (field . !!str:b)) !!int:10)'];
        yield 'if update'             => ['.[] |= if . > 1 then "big" else "small" end', '(|= (iter .) (if (> . !!int:1) !!str:big !!str:small))'];

        yield 'as'                    => ['.a as $foo | $foo', '(as $foo (field . !!str:a) $foo)'];
        yield 'as chain'              => ['.a as $x | .b as $y | .b = $x | .a = $y', '(as $x (field . !!str:a) (as $y (field . !!str:b) (| (= (field . !!str:b) $x) (= (field . !!str:a) $y))))'];
        yield 'as lookup'             => ['.r as $n | .p[] | {"a": $n[.x]}', '(as $n (field . !!str:r) (| (iter (field . !!str:p)) (obj [!!str:a (field $n (field . !!str:x))])))'];
        yield 'as in pipe'            => ['.. | .a as $x | $x', '(| (rec .) (as $x (field . !!str:a) $x))'];
        yield 'as group'              => ['(.[] | key + 1) as $pos | $pos', '(as $pos (| (iter .) (+ (call key) !!int:1)) $pos)'];
        yield 'ref'                   => ['.a.b ref $x | $x = "new" | $x style="double"', '(ref $x (field (field . !!str:a) !!str:b) (| (= $x !!str:new) (= (| $x (call style)) !!str:double)))'];
        yield 'ireduce'               => ['.[] as $item ireduce (0; . + $item)', '(reduce $item (iter .) !!int:0 (+ . $item))'];
        yield 'ireduce group'         => ['(.a, .b) as $i ireduce({}; setpath($i | path; $i))', '(reduce $i (, (field . !!str:a) (field . !!str:b)) (obj) (call setpath (| $i (call path)) $i))'];
        yield 'ireduce then pipe'     => ['. as $i ireduce (0; . + 1) | . * 2', '(| (reduce $i . !!int:0 (+ . !!int:1)) (* . !!int:2))'];
        yield 'prefix reduce'         => ['reduce .[] as $x (0; . + $x)', '(reduce $x (iter .) !!int:0 (+ . $x))'];
        yield 'ireduce complex'       => ['.[] as $item ireduce ({}; .[$item | .name] = ($item | .has) )', '(reduce $item (iter .) (obj) (= (field . (| $item (field . !!str:name))) (| $item (field . !!str:has))))'];

        yield 'interpolation'         => ['"I like \(.value) and \(.another)"', "(interp 'I like ' (field . !!str:value) ' and ' (field . !!str:another))"];
        yield 'interpolation only'    => ['"\(.a)"', '(interp (field . !!str:a))'];
        yield 'interpolation quotes'  => ['"x \(.a | "y") z"', "(interp 'x ' (| (field . !!str:a) !!str:y) ' z')"];
        yield 'interpolation escapes' => ['"a\n\(.b)"', "(interp 'a\n' (field . !!str:b))"];
        yield 'interpolation empty'   => ['"\()"', '(interp .)'];
        yield 'escaped paren'         => ['"\\\(x)"', '!!str:\(x)'];

        yield 'multiline comments'    => [".. | # recurse\n select(has(\"a\")) | # filter\n .a", '(| (| (rec .) (call select (call has !!str:a))) (field . !!str:a))'];
        yield 'object comment'        => ["{\n  \"a\": 1, # one\n  \"b\": 2\n}", '(obj [!!str:a !!int:1] [!!str:b !!int:2])'];
    }

    #[DataProvider('errorProvider')]
    public function testErrors(string $source, string $message): void
    {
        try {
            new PrattParser(new ExpressionLexer()->tokenize($source))->parseAll();
            self::fail('expected a syntax exception');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertStringContainsString($message, $expressionSyntaxException->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorProvider(): iterable
    {
        yield 'unclosed paren'       => ['(.a', 'could not find matching `)`'];
        yield 'unclosed bracket'     => ['.a[0', 'could not find matching `]`'];
        yield 'unclosed collect'     => ['[.a', 'could not find matching `]`'];
        yield 'unclosed brace'       => ['{a: 1', 'could not find matching `}`'];
        yield 'unclosed call'        => ['select(.a', 'could not find matching `)`'];
        yield 'stray closer'         => ['.a)', 'Bad expression'];
        yield 'dangling operator'    => ['.a +', 'Bad expression'];
        yield 'leading operator'     => ['| .a', 'Bad expression'];
        yield 'adjacent values'      => ['1 2', 'Bad expression'];
        yield 'if without end'       => ['if .a then 1', 'Bad expression'];
        yield 'if without then'      => ['if .a 1 end', 'Bad expression'];
        yield 'as without pipe'      => ['.a as $x', 'Bad expression'];
        yield 'as without variable'  => ['.a as x | .b', 'Bad expression'];
        yield 'reduce without parens' => ['.a as $x ireduce 1', 'Bad expression'];
        yield 'keyword as value'     => ['then', 'Bad expression'];
        yield 'question on call'     => ['length?', 'Bad expression'];
        yield 'object missing colon' => ['{1 2}', 'Bad expression'];
        yield 'object shorthand bad' => ['{.a}', 'Bad expression'];
        yield 'bare minus'           => ['-', 'Bad expression'];
        yield 'bad interpolation'    => ['"\(.a +)"', 'Bad expression'];
        yield 'unmatched in string'  => ['"\(.a"', 'unterminated string'];
        yield 'lone colon'           => ['.a : 1', 'Bad expression'];
        yield 'slice no close'       => ['.[1:2', 'could not find matching `]`'];
        yield 'destructuring'        => ['. as [$a] | $a', 'Bad expression'];
    }

    public function testInterpolationErrorOffsetIsAbsolute(): void
    {
        try {
            new PrattParser(new ExpressionLexer()->tokenize('.a | "xy \(.b +)"'))->parseAll();
            self::fail('expected a syntax exception');
        } catch (ExpressionSyntaxException $expressionSyntaxException) {
            self::assertGreaterThanOrEqual(10, $expressionSyntaxException->offset);
        }
    }
}
