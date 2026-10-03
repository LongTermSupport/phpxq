<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\Operators\AssignOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AssignOperator::class)]
final class AssignOperatorTest extends TestCase
{
    #[DataProvider('assignments')]
    public function testAssigns(string $expression, string $input, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, $input));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function assignments(): iterable
    {
        yield 'plain' => ['.a = 2', "a: 1\n", "a: 2\n"];
        yield 'create' => ['.b = 2', "a: 1\n", "a: 1\nb: 2\n"];
        yield 'create nested' => ['.b.c = 2', "a: 1\n", "a: 1\nb:\n  c: 2\n"];
        yield 'create sequence element' => ['.b[1] = 2', "a: 1\n", "a: 1\nb:\n  - null\n  - 2\n"];
        yield 'update' => ['.a |= . + 1', "a: 1\n", "a: 2\n"];
        yield 'update each' => ['.[] |= . * 2', "- 1\n- 2\n", "- 2\n- 4\n"];
        yield 'add assign' => ['.a += 3', "a: 1\n", "a: 4\n"];
        yield 'subtract assign' => ['.a -= 3', "a: 5\n", "a: 2\n"];
        yield 'multiply assign' => ['.a *= 3', "a: 5\n", "a: 15\n"];
        yield 'divide assign' => ['.a /= 2', "a: 6\n", "a: 3\n"];
        yield 'string append' => ['.a += "x"', "a: b\n", "a: bx\n"];
        yield 'array append' => ['.a += [3]', "a:\n  - 1\n", "a:\n  - 1\n  - 3\n"];
        yield 'merge assign' => ['.a *= {"b": 2}', "a:\n  c: 1\n", "a:\n  c: 1\n  b: 2\n"];
        yield 'delete' => ['del(.a)', "a: 1\nb: 2\n", "b: 2\n"];
        yield 'style' => ['.a style="double"', "a: cat\n", "a: \"cat\"\n"];
        yield 'tag' => ['.a tag="!!str"', "a: 1\n", "a: \"1\"\n"];
        yield 'anchor' => ['.a anchor="x"', "a: 1\n", "a: &x 1\n"];
        yield 'line comment' => ['.a line_comment="hi"', "a: 1\n", "a: 1 # hi\n"];
        yield 'head comment' => ['. head_comment="hi"', "a: 1\n", "# hi\na: 1\n"];
        yield 'head comment of a value is written after the entry, as go-yaml does' => ['.a head_comment="hi"', "a: 1\n", "a: 1\n# hi\n"];
        yield 'assign from other path' => ['.b = .a', "a: 1\n", "a: 1\nb: 1\n"];
        yield 'update per target sees target' => ['.a[] |= . + 1', "a:\n  - 1\n  - 2\n", "a:\n  - 2\n  - 3\n"];
        yield 'recursive wrap in array' => ['.. |= [] + .', "zoo:\n  thing:\n    frog: boing\n", "- zoo:\n    - thing:\n        - frog:\n            - boing\n"];
    }
}
