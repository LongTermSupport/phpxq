<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Jq\Runtime\Compiler;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\ProgramHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Compiler::class)]
final class CompilerTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('programs')]
    public function testPrograms(string $program, string $input, array $expected): void
    {
        self::assertSame($expected, ProgramHarness::outputs($program, $input, ['name' => 'value']));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function programs(): iterable
    {
        // paths, slices and iteration
        yield 'identity'                     => ['.', '{"a":[1,2,{"b":null}]}', ['{"a":[1,2,{"b":null}]}']];
        yield 'nested fields'                => ['.a.b.c', '{"a":{"b":{"c":3}}}', ['3']];
        yield 'quoted field'                 => ['."a b"', '{"a b":1}', ['1']];
        yield 'field of null'                => ['.a.b', 'null', ['null']];
        yield 'iterate'                      => ['.a[]', '{"a":[1,2]}', ['1', '2']];
        yield 'iterate object'               => ['.[]', '{"a":1,"b":2}', ['1', '2']];
        yield 'optional iterate'             => ['.[]?', '3', []];
        yield 'optional field'               => ['.a?', '3', []];
        yield 'recursive descent'            => ['[..]', '[1,[2]]', ['[[1,[2]],1,[2],2]']];
        yield 'array slice'                  => ['.[1:3]', '[0,1,2,3,4]', ['[1,2]']];
        yield 'negative slice'               => ['.[-2:]', '[0,1,2,3,4]', ['[3,4]']];
        yield 'string slice by codepoint'    => ['.[2:4]', '"aébcdef"', ['"bc"']];
        yield 'fractional slice'             => ['.[1.5:3.5]', '[0,1,2,3,4,5]', ['[1,2,3]']];
        yield 'index generator'              => ['.["a","b"]', '{"a":1,"b":2}', ['1', '2']];
        yield 'negative index'               => ['.[-1]', '[5,6,7]', ['7']];
        yield 'out of range index'           => ['.[5]', '[5,6,7]', ['null']];
        yield 'subarray indices'             => ['.[[1,2]]', '[0,1,2,1,2]', ['[1,3]']];
        yield 'fractional index floors'      => ['[.[1.1,1.5,1.7]]', '[0,1,2]', ['[1,1,1]']];
        yield 'index on null'                => ['.[0]', 'null', ['null']];

        // construction
        yield 'array of generator'           => ['[.[] | . * 2]', '[1,2,3]', ['[2,4,6]']];
        yield 'empty array'                  => ['[]', 'null', ['[]']];
        yield 'object shorthand'             => ['{a,b}', '{"a":1,"b":2,"c":3}', ['{"a":1,"b":2}']];
        yield 'object variable shorthand'    => ['1 as $x | {$x}', 'null', ['{"x":1}']];
        yield 'object computed key'          => ['{(.k): .v}', '{"k":"x","v":1}', ['{"x":1}']];
        yield 'object quoted key'            => ['{"a b": 1}', 'null', ['{"a b":1}']];
        yield 'object cartesian'             => ['{a:(1,2),b:(3,4)}', 'null', ['{"a":1,"b":3}', '{"a":1,"b":4}', '{"a":2,"b":3}', '{"a":2,"b":4}']];
        yield 'object key generator'         => ['{(("a","b")): 1}', 'null', ['{"a":1}', '{"b":1}']];
        yield 'object keyword key'           => ['{if: 1}', 'null', ['{"if":1}']];
        yield 'object value pipe'            => ['{a: (1 | . + 1)}', 'null', ['{"a":2}']];
        yield 'object duplicate key'         => ['{a: 1, a: 2}', 'null', ['{"a":2}']];
        yield 'string interpolation'         => ['"x\(1+2)y"', 'null', ['"x3y"']];
        yield 'interpolation of a string'    => ['"a\("b")c"', 'null', ['"abc"']];
        yield 'interpolation cartesian'      => ['"\(1,2) \(3,4)"', 'null', ['"1 3"', '"2 3"', '"1 4"', '"2 4"']];
        yield 'interpolation of arrays'      => ['"v=\([1,"a"])"', 'null', ['"v=[1,\"a\"]"']];
        yield 'format interpolation'         => ['@base64 "x\(.)y"', '"hi"', ['"xaGk=y"']];
        yield 'bare format'                  => ['@json', '[1,"a"]', ['"[1,\"a\"]"']];
        yield 'text format'                  => ['@text "<\(.)>"', '[1]', ['"<[1]>"']];
        yield 'loc'                          => ['$__loc__', 'null', ['{"file":"<top-level>","line":1}']];

        // operators
        yield 'comma'                        => ['1,2,3', 'null', ['1', '2', '3']];
        yield 'addition cartesian'           => ['[(1,2) + (10,20)]', 'null', ['[11,12,21,22]']];
        yield 'multiplication cartesian'     => ['[(1,2) * (3,4)]', 'null', ['[3,6,4,8]']];
        yield 'subtraction cartesian'        => ['[(1,2) - (3,4)]', 'null', ['[-2,-1,-3,-2]']];
        yield 'comparison cartesian'         => ['[(1,2) == (1,2)]', 'null', ['[true,false,false,true]']];
        yield 'negation generator'           => ['[-(1,2)]', 'null', ['[-1,-2]']];
        yield 'unary minus of field'         => ['[.[] | -.]', '[1,-2]', ['[-1,2]']];
        yield 'object addition'              => ['{a:1} + {b:2}', 'null', ['{"a":1,"b":2}']];
        yield 'deep merge'                   => ['{a:{b:1}} * {a:{c:2}}', 'null', ['{"a":{"b":1,"c":2}}']];
        yield 'array subtraction'            => ['[1,2,3,2] - [2]', 'null', ['[1,3]']];
        yield 'string repeat'                => ['"abc" * 2', 'null', ['"abcabc"']];
        yield 'string split by division'     => ['"a,b" / ","', 'null', ['["a","b"]']];
        yield 'modulo signs'                 => ['[.[] % 7]', '[-7,-1,5,8,15]', ['[0,-1,5,1,1]']];
        yield 'null plus number'             => ['null + 1', 'null', ['1']];
        yield 'int equals float'             => ['. == 1', '1.0', ['true']];
        yield 'total order'                  => ['[.[] | . < 2]', '[null,false,true,1,"a",[1],{"a":1}]', ['[true,true,true,true,false,false,false]']];
        yield 'and cartesian'                => ['[(true,false) and (true,false)]', 'null', ['[true,false,false]']];
        yield 'or cartesian'                 => ['[(true,false) or (true,false)]', 'null', ['[true,true,false]']];
        yield 'and short circuits'           => ['false and error("x")', 'null', ['false']];
        yield 'or short circuits'            => ['true or error("x")', 'null', ['true']];
        yield 'alternative'                  => ['[(1,null,false) // 7]', 'null', ['[1]']];
        yield 'alternative fallback'         => ['[(null,false) // 7]', 'null', ['[7]']];
        yield 'alternative of empty'         => ['[empty // 7]', 'null', ['[7]']];
        yield 'alternative swallows errors'  => ['[error("x") // 7]', 'null', ['[7]']];
        yield 'alternative in iteration'     => ['[.[] // 7]', '[null,false]', ['[7]']];
        yield 'conditional'                  => ['if . then 1 else 2 end', 'null', ['2']];
        yield 'conditional generator'        => ['[if (true,false) then 1 else 2 end]', 'null', ['[1,2]']];
        yield 'conditional without else'     => ['[.[] | if . > 1 then "big" end]', '[1,2]', ['[1,"big"]']];
        yield 'elif chain'                   => ['if . == 1 then "a" elif . == 2 then "b" else "c" end', '2', ['"b"']];

        // try / catch and errors
        yield 'try catch'                    => ['try error("x") catch .', 'null', ['"x"']];
        yield 'error object value'           => ['try error({a:1}) catch .a', 'null', ['1']];
        yield 'error null caught'            => ['try error(null) catch .', 'null', ['null']];
        yield 'try without catch'            => ['[try error("x")]', 'null', ['[]']];
        yield 'postfix question'             => ['[.[] | (.a, .a)?]', '[null,true,{"a":1}]', ['[null,null,1,1]']];
        yield 'try body keeps earlier'       => ['[.[] | try (if . == 1 then error("e") else . end)]', '[0,1,2]', ['[0,2]']];
        yield 'catch type error'             => ['try (1/0) catch .', 'null', ['"number (1) and number (0) cannot be divided because the divisor is zero"']];
        yield 'error inside continuation'    => ['[.[] | try (1, 2) | if . == 2 then error("late") else . end] ?', '[1]', []];
        yield 'try does not catch downstream' => ['try ((1,2) | error("x")) catch "c", "after"', 'null', ['"c"', '"after"']];

        // variables and patterns
        yield 'variable binding'             => ['. as $x | [$x, $x + 1]', '5', ['[5,6]']];
        yield 'binding generator'            => ['[.[] as $x | $x * 2]', '[1,2]', ['[2,4]']];
        yield 'array destructuring'          => ['. as [$a,$b] | {a:$a,b:$b}', '[1,2]', ['{"a":1,"b":2}']];
        yield 'object destructuring'         => ['. as {a:$x, b:[$y,$z]} | [$x,$y,$z]', '{"a":1,"b":[2,3]}', ['[1,2,3]']];
        yield 'destructuring shorthand'      => ['. as {$a, $b:[$c]} | [$a,$b,$c]', '{"a":1,"b":[2]}', ['[1,[2],2]']];
        yield 'destructuring missing'        => ['.[] as [$a,$b] | {a:$a,b:$b}', '[[1,2],[3]]', ['{"a":1,"b":2}', '{"a":3,"b":null}']];
        yield 'destructuring key expression' => ['. as {("a","b"): $x} | $x', '{"a":1,"b":2}', ['1', '2']];
        yield 'destructuring key variable'   => ['. as {k: $k, ($k): $v} | $v', '{"k":"name","name":"found"}', ['"found"']];
        yield 'destructuring alternatives'   => ['.[] as [$a] ?// $a | $a', '[[1],2]', ['1', '2']];
        yield 'alternatives bind all'        => ['. as [$a] ?// $b | [$a, $b]', '[1]', ['[1,null]']];
        yield 'alternatives on error'        => ['.[] as {a:$a} ?// [$a] | $a', '[{"a":1},[2]]', ['1', '2']];
        yield 'alternatives body error'      => ['. as [$a] ?// $a | if ($a|type) == "number" then error("x") else $a end', '[1]', ['[1]']];
        yield 'shadowing'                    => ['1 as $x | 2 as $x | $x', 'null', ['2']];
        yield 'binding keeps input'          => ['1 as $x | .', '"in"', ['"in"']];
        yield 'global variables'             => ['$name', 'null', ['"value"']];

        // reduce / foreach / label
        yield 'reduce'                       => ['reduce .[] as $x (0; . + $x)', '[1,2,3]', ['6']];
        yield 'reduce pattern'               => ['reduce .[] as [$a,$b] (0; . + $a*$b)', '[[1,2],[3,4]]', ['14']];
        yield 'reduce empty source'          => ['reduce empty as $x (5; . + 1)', 'null', ['5']];
        yield 'reduce empty update'          => ['reduce range(3) as $x (0; empty)', 'null', ['null']];
        yield 'reduce init generator'        => ['[reduce range(3) as $x (0,10; . + $x)]', 'null', ['[3,13]']];
        yield 'reduce last update wins'      => ['reduce range(2) as $x (0; ., 5)', 'null', ['5']];
        yield 'foreach'                      => ['[foreach .[] as $x (0; . + $x)]', '[1,2,3]', ['[1,3,6]']];
        yield 'foreach extract'              => ['[foreach .[] as $x (0; . + $x; [$x, .])]', '[1,2,3]', ['[[1,1],[2,3],[3,6]]']];
        yield 'foreach init generator'       => ['[foreach .[] as $x (0, 1; . + $x)]', '[1,2]', ['[1,3,2,4]']];
        yield 'foreach empty update'         => ['[foreach range(5) as $x (0; empty; .)]', 'null', ['[]']];
        yield 'label break'                  => ['[label $f | range(10) | ., (select(. == 3) | break $f)]', 'null', ['[0,1,2,3]']];
        yield 'nested labels'                => ['[label $a | label $b | (1, break $a, 2), 3]', 'null', ['[1]']];
        yield 'foreach with break'           => ['[label $out | foreach .[] as $item ([3, null]; if .[0] < 1 then break $out else [.[0] -1, $item] end; .[1])]', '[11,22,33,44,55]', ['[11,22,33]']];
        yield 'first stops early'            => ['first(range(10;20))', 'null', ['10']];

        // functions
        yield 'simple function'              => ['def f: . + 1; f', '1', ['2']];
        yield 'closure parameter'            => ['def f(x): x * 2; [f(.[])]', '[1,2]', ['[2,4]']];
        yield 'value parameter'              => ['def f($a; $b): $a + $b; f(1;2)', 'null', ['3']];
        yield 'value parameter cartesian'    => ['def f($a; $b): [$a, $b]; [f(1,2; 3,4)]', 'null', ['[[1,3],[1,4],[2,3],[2,4]]']];
        yield 'value parameter as closure'   => ['def f($a): a; f(5)', 'null', ['5']];
        yield 'closure sees callers input'   => ['def f(g): [g]; 3 | f(. + 1)', 'null', ['[4]']];
        yield 'nested definition'            => ['def f: def g: 3; g; f', 'null', ['3']];
        yield 'recursion'                    => ['def fac: if . == 1 then 1 else . * (. - 1 | fac) end; [.[] | fac]', '[1,2,3,4]', ['[1,2,6,24]']];
        yield 'definitions see earlier ones' => ['def a: 1; def b: a + 1; b', 'null', ['2']];
        yield 'closure captures variables'   => ['def f(x): 10 as $v | x; 5 as $v | f($v)', 'null', ['5']];
        yield 'closure over parameter'       => ['def f(a): def g(b): a + b; g(10); f(1)', 'null', ['11']];
        yield 'parameter used twice'         => ['def f(x): x | x; 2 | f(. * .)', 'null', ['16']];
        yield 'redefinition shadows'         => ['def f: 1; def f: 2; f', 'null', ['2']];
        yield 'definition before use only'   => ['def f: 1; def g: f; def f: 2; [f, g]', 'null', ['[2,1]']];
        yield 'same name different arity'    => ['def f: 1; def f(x): 2; [f, f(0)]', 'null', ['[1,2]']];
        yield 'deep recursion'               => ['def f: if . < 10000 then . + 1 | f else . end; 0 | f', 'null', ['10000']];
        yield 'recurse builtin'              => ['[recurse(if . < 3 then . + 1 else empty end)]', '0', ['[0,1,2,3]']];
        yield 'prelude function'             => ['map(. + 1)', '[1,2]', ['[2,3]']];
        yield 'user shadows builtin'         => ['def map(f): "mine"; map(.)', '[1]', ['"mine"']];
        yield 'user shadows intrinsic'       => ['def select(f): "mine"; select(.)', '1', ['"mine"']];
        yield 'native with generator args'   => ['[pair(1,2; 3,4)]', 'null', ['[[1,3],[2,3],[1,4],[2,4]]']];
        yield 'native stream builtin'        => ['[range(3)]', 'null', ['[0,1,2]']];
        yield 'native in path position'      => ['path(extra)', '[1]', ['[]', '["extra"]']];

        // path expressions
        yield 'path of field'                => ['path(.a[0].b)', 'null', ['["a",0,"b"]']];
        yield 'paths of nested'              => ['[paths]', '[1,[[],{"a":2}]]', ['[[0],[1],[1,0],[1,1],[1,1,"a"]]']];
        yield 'path of recursive descent'    => ['[path(..)]', '[[1]]', ['[[],[0],[0,0]]']];
        yield 'path through select'          => ['[path(.[] | select(. > 1))]', '[1,2,3]', ['[[1],[2]]']];
        yield 'path through optional'        => ['path(.a | .b?)', '{"a":{"b":1}}', ['["a","b"]']];
        yield 'path through if'              => ['[path(if .a then .a else .b end)]', '{"a":1}', ['[["a"]]']];
        yield 'path through alternative'     => ['path(.a // .b)', '{"b":1}', ['["b"]']];
        yield 'path of empty'                => ['[path(empty)]', 'null', ['[]']];
        yield 'path through getpath'         => ['path(getpath(["a","b"]))', 'null', ['["a","b"]']];
        yield 'path through reduce'          => ['path(reduce (0,1) as $i (.; .[$i]))', '[[5,6]]', ['[0,1]']];
        yield 'path through binding'         => ['path(. as $x | .a)', '{"a":1}', ['["a"]']];
        yield 'path through function'        => ['def f: .a; path(f)', '{"a":1}', ['["a"]']];
        yield 'path through closure'         => ['def f(g): g | .b; path(f(.a))', '{"a":{"b":1}}', ['["a","b"]']];
        yield 'path through try'             => ['[path(.a?, .b)]', '{"a":1}', ['[["a"],["b"]]']];
        yield 'path through label'           => ['[path(label $x | .a, break $x, .b)]', '{"a":1}', ['[["a"]]']];
        yield 'path of slice'                => ['path(.[1:2])', '[1,2,3]', ['[{"start":1,"end":2}]']];
        yield 'path with null input'         => ['path(.a.b)', 'null', ['["a","b"]']];
        yield 'path of foreach'              => ['[path(foreach (1,2) as $i (.; .[0]))]', '[[[1]]]', ['[[0],[0,0]]']];
        yield 'getpath'                      => ['getpath(["a",1])', '{"a":[1,2]}', ['2']];
        yield 'getpath missing'              => ['getpath(["x","y"])', '{"a":1}', ['null']];
        yield 'del via paths'                => ['del(.[1])', '[1,2,3]', ['[1,3]']];
        yield 'del generator'                => ['del(.[] | select(. > 1))', '[1,2,3]', ['[1]']];
        yield 'del several'                  => ['del(.a, .b)', '{"a":1,"b":2,"c":3}', ['{"c":3}']];
        yield 'to_entries'                   => ['to_entries', '{"a":1,"b":2}', ['[{"key":"a","value":1},{"key":"b","value":2}]']];
        yield 'with_entries'                 => ['with_entries(.value += 1)', '{"a":1,"b":2}', ['{"a":2,"b":3}']];

        // assignment
        yield 'set'                          => ['.a = 1', '{"b":2}', ['{"b":2,"a":1}']];
        yield 'set from input'               => ['.foo = .bar', '{"bar":42}', ['{"bar":42,"foo":42}']];
        yield 'set creates structure'        => ['.a.b.c = 1', 'null', ['{"a":{"b":{"c":1}}}']];
        yield 'set pads arrays'              => ['.[2] = 1', '[]', ['[null,null,1]']];
        yield 'set negative index'           => ['.[-1] = 9', '[1,2]', ['[1,9]']];
        yield 'set slice'                    => ['.[1:3] = ["x"]', '[1,2,3,4]', ['[1,"x",4]']];
        yield 'set every element'            => ['.[] = 1', '[1,null,3]', ['[1,1,1]']];
        yield 'set several paths'            => ['(.a,.b) = (1,2)', 'null', ['{"a":1,"b":1}', '{"a":2,"b":2}']];
        yield 'set rhs generator'            => ['.a = (1,2)', 'null', ['{"a":1}', '{"a":2}']];
        yield 'update'                       => ['.a |= . + 1', '{"a":1}', ['{"a":2}']];
        yield 'update every element'         => ['.[] |= . + 1', '[1,2]', ['[2,3]']];
        yield 'update first output only'     => ['.[] |= (., 1)', '[1,2]', ['[1,2]']];
        yield 'update with empty deletes'    => ['(.[] | select(. >= 2)) |= empty', '[1,5,3,0,7]', ['[1,0]']];
        yield 'update select'                => ['.[] |= select(. % 2 == 0)', '[0,1,2,3,4,5]', ['[0,2,4]']];
        yield 'update many indexes'          => ['.foo[1,4,2,3] |= empty', '{"foo":[0,1,2,3,4,5]}', ['{"foo":[0,5]}']];
        yield 'update object field delete'   => ['.a |= empty', '{"a":1,"b":2}', ['{"b":2}']];
        yield 'update with closure'          => ['def inc(x): x |= . + 1; inc(.[].a)', '[{"a":1},{"a":2}]', ['[{"a":2},{"a":3}]']];
        yield 'update recursive'             => ['(.. | select(type == "number")) |= . + 1', '[1,[2,{"a":3}]]', ['[2,[3,{"a":4}]]']];
        yield 'update nested object'         => ['.[0].a |= {"old":., "new":(.+1)}', '[{"a":1,"b":2}]', ['[{"a":{"old":1,"new":2},"b":2}]']];
        yield 'update slice'                 => ['.a[1:2] |= ["x","y"]', '{"a":[1,2,3]}', ['{"a":[1,"x","y",3]}']];
        yield 'update getpath'               => ['getpath(["a","b"]) |= 5', 'null', ['{"a":{"b":5}}']];
        yield 'arithmetic update'            => ['.a += 1', '{"a":1}', ['{"a":2}']];
        yield 'arithmetic update all'        => ['.[] += 2, .[] -= 2, .[] *= 2, .[] /= 2, .[] %= 2', '[1,3,5]', ['[3,5,7]', '[-1,1,3]', '[2,6,10]', '[0.5,1.5,2.5]', '[1,1,1]']];
        yield 'arithmetic update rhs input'  => ['.foo += .foo', '{"foo":2}', ['{"foo":4}']];
        yield 'arithmetic update generator'  => ['.a += (1,2)', '{"a":0}', ['{"a":1}', '{"a":2}']];
        yield 'alternative update'           => ['.[] //= .[0]', '["hello",true,false,[false],null]', ['["hello",true,"hello",[false],"hello"]']];
        yield 'update through object path'   => ['.a.b |= 1', 'null', ['{"a":{"b":1}}']];
        yield 'overlapping paths'            => ['(.a, .a.b) |= (if type == "object" then {b: 5} else . + 1 end)', '{"a":{"b":1}}', ['{"a":{"b":6}}']];
        yield 'big batch update'             => ['[range(1000)] | .[] += 1 | add', 'null', ['500500']];
    }

    #[DataProvider('runtimeErrors')]
    public function testRuntimeErrors(string $program, string $input, string $message): void
    {
        self::assertSame($message, ProgramHarness::error($program, $input));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function runtimeErrors(): iterable
    {
        yield 'index number'            => ['.a', '1', 'Cannot index number with string ("a")'];
        yield 'index object by number'  => ['.[0]', '{}', 'Cannot index object with number (0)'];
        yield 'iterate number'          => ['.[]', '123', 'Cannot iterate over number (123)'];
        yield 'iterate null'            => ['.[]', 'null', 'Cannot iterate over null (null)'];
        yield 'error string'            => ['error("custom")', 'null', 'custom'];
        yield 'error object'            => ['error({a:1})', 'null', '{"a":1}'];
        yield 'object key not string'   => ['{(1,2): 3}', 'null', 'Object keys must be strings'];
        yield 'addition'                => ['1 + "a"', 'null', 'number (1) and string ("a") cannot be added'];
        yield 'division by zero'        => ['1 / 0', 'null', 'number (1) and number (0) cannot be divided because the divisor is zero'];
        yield 'modulo by zero'          => ['1 % 0', 'null', 'number (1) and number (0) cannot be divided (remainder) because the divisor is zero'];
        yield 'invalid path'            => ['path(1)', 'null', 'Invalid path expression with result 1'];
        yield 'invalid path access'     => ['path(.a | map(select(.b == 0)) | .[0])', '{"a":[{"b":0}]}', 'Invalid path expression near attempt to access element 0 of [{"b":0}]'];
        yield 'invalid path iterate'    => ['path(.a | map(select(.b == 0)) | .[])', '{"a":[{"b":0}]}', 'Invalid path expression near attempt to iterate through [{"b":0}]'];
        yield 'invalid path result'     => ['path(.a | map(select(.b == 0)))', '{"a":[{"b":0}]}', 'Invalid path expression with result [{"b":0}]'];
        yield 'assign to non-path'      => ['(map(select(.a == 1))[].b) = 10', '[{"a":0},{"a":1}]', 'Invalid path expression near attempt to iterate through [{"a":1}]'];
        yield 'assign to function'      => ['def x: reverse; x = 10', '[0,1,2]', 'Invalid path expression with result [2,1,0]'];
        yield 'update through scalar'   => ['getpath(["a",0,"b"]) |= 5', '{"a":0}', 'Cannot index number with number (0)'];
        yield 'long value truncated'    => ['-.', '"very-long-long-long-long-string"', 'string ("very-long-long-long-long...") cannot be negated'];
        yield 'set nan index'           => ['.[nan] = 9', '[0,1,2]', 'Cannot set array element at NaN index'];
        yield 'set string slice'        => ['.[1:2] = "x"', '"foobar"', 'Cannot update string slices'];
        yield 'uncaught break'          => ['[.[] | error]', '["a"]', 'a'];
    }

    public function testOutputsBeforeAnErrorAreStillEmitted(): void
    {
        $seen = [];

        try {
            $compiler = ProgramHarness::registry();
            $parser   = new \LTS\PhpXq\Jq\Parser\Parser(new \LTS\PhpXq\Jq\Parser\Lexer());
            $program  = new Compiler($compiler, $parser, new \LTS\PhpXq\Jq\Runtime\FileModuleLoader([], $parser, new \LTS\PhpXq\Json\JsonDecoder()))
                ->compile($parser->parse('1, error("stop"), 3'))
            ;
            $program->run(new Eval\Support\StubContext(), null, static function (mixed $value) use (&$seen): void {
                $seen[] = $value;
            });
            self::fail('expected an error');
        } catch (JqException $jqException) {
            self::assertSame('stop', $jqException->getMessage());
        }

        self::assertSame([1], $seen);
    }

    #[DataProvider('compileErrors')]
    public function testCompileErrors(string $program, string $message): void
    {
        self::assertSame($message, ProgramHarness::compileError($program));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function compileErrors(): iterable
    {
        yield 'undefined function'      => ['foo', 'foo/0 is not defined at <top-level>, line 1:'];
        yield 'undefined with arity'    => ['map(1; 2)', 'map/2 is not defined at <top-level>, line 1:'];
        yield 'undefined variable'      => ['. as $foo | [$foo, $bar]', '$bar is not defined at <top-level>, line 1:'];
        yield 'break without label'     => ['. as $foo | break $foo', '$*label-foo is not defined at <top-level>, line 1:'];
        yield 'constant number key'     => ['{(0):1}', 'Cannot use number (0) as object key at <top-level>, line 1:'];
        yield 'constant boolean key'    => ['{(true):1}', 'Cannot use boolean (true) as object key at <top-level>, line 1:'];
        yield 'mixed constant key'      => ['{non_const:., (0):1}', 'Cannot use number (0) as object key at <top-level>, line 1:'];
        yield 'pattern boolean key'     => ['. as {(true):$foo} | $foo', 'Cannot use boolean (true) as object key at <top-level>, line 1:'];
        yield 'unknown format'          => ['@nope', 'nope is not a valid format at <top-level>, line 1:'];
        yield 'parameter with args'     => ['def f(g): g(1); f(.)', 'g/1 is not defined at <top-level>, line 1:'];
        yield 'label out of scope'      => ['(label $a | 1), break $a', '$*label-a is not defined at <top-level>, line 1:'];
        yield 'variable out of scope'   => ['(1 as $x | $x), $x', '$x is not defined at <top-level>, line 1:'];
        yield 'function out of scope'   => ['(def f: 1; f), f', 'f/0 is not defined at <top-level>, line 1:'];
    }

    public function testCompilingTwiceReusesThePrelude(): void
    {
        $parser   = new \LTS\PhpXq\Jq\Parser\Parser(new \LTS\PhpXq\Jq\Parser\Lexer());
        $compiler = new Compiler(ProgramHarness::registry(), $parser, new \LTS\PhpXq\Jq\Runtime\FileModuleLoader([], $parser, new \LTS\PhpXq\Json\JsonDecoder()));

        $first  = $compiler->compile($parser->parse('map(. + 1)'));
        $second = $compiler->compile($parser->parse('map(. * 2)'));

        $results = [];
        foreach ([$first, $second] as $program) {
            $program->run(new Eval\Support\StubContext(), [1, 2], static function (mixed $value) use (&$results): void {
                $results[] = $value;
            });
        }

        self::assertSame([[2, 3], [2, 4]], $results);
    }

    public function testAProgramCanRunInsideAnotherOfTheSameCompiler(): void
    {
        $parser   = new \LTS\PhpXq\Jq\Parser\Parser(new \LTS\PhpXq\Jq\Parser\Lexer());
        $compiler = new Compiler(ProgramHarness::registry(), $parser, new \LTS\PhpXq\Jq\Runtime\FileModuleLoader([], $parser, new \LTS\PhpXq\Json\JsonDecoder()));
        $inner    = $compiler->compile($parser->parse('$x'), ['x']);
        $outer    = $compiler->compile($parser->parse('$x'), ['x']);

        $seen = [];
        $outer->run(new Eval\Support\StubContext(['x' => 'outer'], []), null, static function (mixed $value) use ($inner, &$seen): void {
            $seen[] = $value;
            $inner->run(new Eval\Support\StubContext(['x' => 'inner'], []), null, static function (mixed $nested) use (&$seen): void {
                $seen[] = $nested;
            });
        });
        $outer->run(new Eval\Support\StubContext(['x' => 'again'], []), null, static function (mixed $value) use (&$seen): void {
            $seen[] = $value;
        });

        self::assertSame(['outer', 'inner', 'again'], $seen);
    }
}
