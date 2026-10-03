<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\Operators\CollectionCalls;
use LTS\PhpXq\Yq\Runtime\Operators\DateCalls;
use LTS\PhpXq\Yq\Runtime\Operators\FormatCalls;
use LTS\PhpXq\Yq\Runtime\Operators\MetaCalls;
use LTS\PhpXq\Yq\Runtime\Operators\NavigationCalls;
use LTS\PhpXq\Yq\Runtime\Operators\RegexCalls;
use LTS\PhpXq\Yq\Runtime\Operators\SelectionCalls;
use LTS\PhpXq\Yq\Runtime\Operators\SortingCalls;
use LTS\PhpXq\Yq\Runtime\Operators\StringCalls;
use LTS\PhpXq\Yq\Runtime\Operators\StructureCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CollectionCalls::class)]
#[CoversClass(DateCalls::class)]
#[CoversClass(FormatCalls::class)]
#[CoversClass(MetaCalls::class)]
#[CoversClass(NavigationCalls::class)]
#[CoversClass(RegexCalls::class)]
#[CoversClass(SelectionCalls::class)]
#[CoversClass(SortingCalls::class)]
#[CoversClass(StringCalls::class)]
#[CoversClass(StructureCalls::class)]
final class CallsTest extends TestCase
{
    #[DataProvider('calls')]
    public function testCall(string $expression, string $input, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, $input, '' === $input));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function calls(): iterable
    {
        yield 'select' => ['.[] | select(. > 1)', "- 1\n- 2\n- 3\n", "2\n3\n"];
        yield 'select with and' => ['.[] | select(. > 1 and . < 3)', "- 1\n- 2\n- 3\n", "2\n"];
        yield 'has' => ['has("a")', "a: 1\n", "true\n"];
        yield 'not has' => ['has("b")', "a: 1\n", "false\n"];
        yield 'contains' => ['contains(["a"])', "- a\n- b\n", "true\n"];
        yield 'length of map' => ['length', "a: 1\nb: 2\n", "2\n"];
        yield 'length of null' => ['length', "null\n", "0\n"];
        yield 'reverse' => ['reverse', "- 1\n- 2\n- 3\n", "- 3\n- 2\n- 1\n"];
        yield 'sort' => ['sort', "- 3\n- 1\n- 2\n", "- 1\n- 2\n- 3\n"];
        yield 'sort_by' => ['sort_by(.a)', "- a: 2\n- a: 1\n", "- a: 1\n- a: 2\n"];
        yield 'sort_by desc via reverse' => ['sort_by(.a) | reverse', "- a: 1\n- a: 2\n", "- a: 2\n- a: 1\n"];
        yield 'unique' => ['unique', "- 1\n- 1\n- 2\n", "- 1\n- 2\n"];
        yield 'unique_by' => ['unique_by(.a)', "- a: 1\n  b: x\n- a: 1\n  b: y\n", "- a: 1\n  b: x\n"];
        yield 'group_by' => ['group_by(.a)', "- a: 1\n- a: 2\n- a: 1\n", "- - a: 1\n  - a: 1\n- - a: 2\n"];
        yield 'flatten' => ['flatten', "- 1\n- - 2\n  - - 3\n", "- 1\n- 2\n- 3\n"];
        yield 'flatten depth' => ['flatten(1)', "- 1\n- - 2\n  - - 3\n", "- 1\n- 2\n- - 3\n"];
        yield 'min' => ['min', "- 3\n- 1\n- 2\n", "1\n"];
        yield 'max' => ['max', "- 3\n- 1\n- 2\n", "3\n"];
        yield 'add' => ['add', "- 1\n- 2\n- 3\n", "6\n"];
        yield 'any' => ['any', "- false\n- true\n", "true\n"];
        yield 'all' => ['all', "- false\n- true\n", "false\n"];
        yield 'map' => ['map(. + 1)', "- 1\n- 2\n", "- 2\n- 3\n"];
        yield 'map_values' => ['map_values(. + 1)', "a: 1\nb: 2\n", "a: 2\nb: 3\n"];
        yield 'keys of seq' => ['keys', "- a\n- b\n", "- 0\n- 1\n"];
        yield 'to_entries' => ['to_entries', "a: 1\n", "- key: a\n  value: 1\n"];
        yield 'from_entries' => ['from_entries', "- key: a\n  value: 1\n", "a: 1\n"];
        yield 'with_entries' => ['with_entries(.value += 1)', "a: 1\n", "a: 2\n"];
        yield 'pick' => ['pick(["a"])', "a: 1\nb: 2\n", "a: 1\n"];
        yield 'omit' => ['omit(["a"])', "a: 1\nb: 2\n", "b: 2\n"];
        yield 'del seq element' => ['del(.[0])', "- a\n- b\n", "- b\n"];
        yield 'delpaths' => ['delpaths([["a"]])', "a: 1\nb: 2\n", "b: 2\n"];
        yield 'path' => ['[.a.b | path]', "a:\n  b: 1\n", "- - a\n  - b\n"];
        yield 'paths via recursion' => ['[.. | path | join(".")]', "a:\n  b: 1\n", "- \"\"\n- a\n- a.b\n"];
        yield 'parent' => ['.a.b | parent', "a:\n  b: 1\n", "b: 1\n"];
        yield 'key' => ['.a | key', "a: 1\n", "a\n"];
        yield 'kind' => ['.a | kind', "a: 1\n", "scalar\n"];
        yield 'tag' => ['.a | tag', "a: 1\n", "!!int\n"];
        yield 'type alias' => ['.a | type', "a: 1.5\n", "!!float\n"];
        yield 'line' => ['.b | line', "a: 1\nb: 2\n", "2\n"];
        yield 'to_number' => ['"12" | to_number', '', "12\n"];
        yield 'to_string' => ['.a | to_string', "a: 1\n", "1\n"];
        yield 'upcase' => ['upcase', "cat\n", "CAT\n"];
        yield 'downcase' => ['downcase', "CAT\n", "cat\n"];
        yield 'trim' => ['trim', "\"  cat  \"\n", "cat\n"];
        yield 'ltrimstr' => ['ltrimstr("c")', "cat\n", "at\n"];
        yield 'rtrimstr' => ['rtrimstr("t")', "cat\n", "ca\n"];
        yield 'startswith' => ['startswith("c")', "cat\n", "true\n"];
        yield 'endswith' => ['endswith("c")', "cat\n", "false\n"];
        yield 'split' => ['split(",")', "a,b\n", "- a\n- b\n"];
        yield 'join' => ['join("-")', "- a\n- b\n", "a-b\n"];
        yield 'sub' => ['sub("c", "b")', "cat\n", "bat\n"];
        yield 'test' => ['test("^c")', "cat\n", "true\n"];
        yield 'match' => ['[match("a+").string]', "baat\n", "- aa\n"];
        yield 'capture' => ['capture("(?P<x>a+)")', "baat\n", "x: aa\n"];
        yield 'ascii_downcase alias' => ['ascii_downcase', "CAT\n", "cat\n"];
        yield 'base64' => ['@base64', "cat\n", "Y2F0\n"];
        yield 'base64d' => ['@base64d', "Y2F0\n", "cat\n"];
        yield 'uri' => ['@uri', "\"a b\"\n", "a+b\n"];
        yield 'sh' => ['@sh', "\"it's\"\n", "it\\'s\n"];
        yield 'html' => ['@html', "\"<a>\"\n", "&lt;a&gt;\n"];
        yield 'csv' => ['@csv', "- a\n- 1\n", "a,1\n"];
        yield 'tsv' => ['@tsv', "- a\n- 1\n", "a\t1\n"];
        yield 'from_unix' => ['from_unix', "0\n", "1970-01-01T00:00:00Z\n"];
        yield 'to_unix' => ['to_unix', "\"1970-01-01T00:00:10Z\"\n", "10\n"];
        yield 'tz' => ['tz("Asia/Tokyo")', "\"1970-01-01T00:00:00Z\"\n", "1970-01-01T09:00:00+09:00\n"];
        yield 'format_datetime' => ['format_datetime("2006")', "\"2001-12-15T02:59:43Z\"\n", "2001\n"];
        yield 'split_doc' => ['.[] | split_doc', "- a: 1\n- b: 2\n", "a: 1\n---\nb: 2\n"];
        yield 'document_index' => ['document_index', "a: 1\n---\nb: 2\n", "0\n---\n1\n"];
        yield 'explode' => ['explode(.)', "a: &x\n  c: 1\nb: *x\n", "a:\n  c: 1\nb:\n  c: 1\n"];
        yield 'getpath' => ['getpath(["a", "b"])', "a:\n  b: 1\n", "1\n"];
        yield 'setpath' => ['setpath(["a", "b"]; 2)', "a:\n  b: 1\n", "a:\n  b: 2\n"];
        yield 'env default' => ['env(HOME_UNSET_FOR_TEST) // "none"', "a: 1\n", "none\n"];
        yield 'first' => ['first', "- 1\n- 2\n", "1\n"];
        yield 'last' => ['last', "- 1\n- 2\n", "2\n"];
        yield 'pivot' => ['pivot', "- [a, b]\n- [c, d]\n", "- - a\n  - c\n- - b\n  - d\n"];
        yield 'shuffle keeps length' => ['shuffle | length', "- 1\n- 2\n- 3\n", "3\n"];
        yield 'toyaml' => ['to_yaml', "a: 1\n", "a: 1\n"];
        yield 'ireduce style' => ['[.[] | select(. != "b")]', "- a\n- b\n", "- a\n"];
        yield 'alternative' => ['.z // "d"', "a: 1\n", "d\n"];
        yield 'and or not' => ['(true and false) or (false | not)', '', "true\n"];
        yield 'comparison strings' => ['"a" < "b"', '', "true\n"];
        yield 'equality across types' => ['1 == "1"', '', "true\n"];
    }
}
