<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Tests\Unit\Yq\Runtime\Support\YqHarness;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Evaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Evaluator::class)]
final class EvaluatorTest extends TestCase
{
    #[DataProvider('expressions')]
    public function testEvaluates(string $expression, string $input, string $expected): void
    {
        self::assertSame($expected, YqHarness::run($expression, $input));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function expressions(): iterable
    {
        yield 'identity' => ['.', "a: 1\n", "a: 1\n"];
        yield 'field' => ['.a', "a: 1\n", "1\n"];
        yield 'nested field' => ['.a.b', "a:\n  b: cat\n", "cat\n"];
        yield 'missing field is null' => ['.z', "a: 1\n", "null\n"];
        yield 'index' => ['.[1]', "- a\n- b\n", "b\n"];
        yield 'negative index' => ['.[-1]', "- a\n- b\n", "b\n"];
        yield 'iterate' => ['.[]', "- a\n- b\n", "a\nb\n"];
        yield 'iterate map' => ['.[]', "x: 1\ny: 2\n", "1\n2\n"];
        yield 'collect' => ['[.[] | . + 1]', "- 1\n- 2\n", "- 2\n- 3\n"];
        yield 'slice' => ['.[1:3]', "- a\n- b\n- c\n- d\n", "- b\n- c\n"];
        yield 'optional slice of a mapping yields nothing' => ['.[1:3]?', "m: 1\n", ''];
        yield 'optional slice still slices' => ['.[1:3]?', "- a\n- b\n- c\n- d\n", "- b\n- c\n"];
        yield 'object construct' => ['{"n": .a}', "a: 1\n", "n: 1\n"];
        yield 'conditional' => ['(.a | select(. == 1)) // "no"', "a: 2\n", "no\n"];
        yield 'recursive descent' => ['[..]', "a:\n  b: 1\n", "- a:\n    b: 1\n- b: 1\n- 1\n"];
        yield 'variable' => ['.a as $x | $x + 1', "a: 1\n", "2\n"];
        yield 'reduce' => ['reduce .[] as $i (0; . + $i)', "- 1\n- 2\n- 3\n", "6\n"];
        yield 'interpolation' => ['"v=\(.a)"', "a: 1\n", "v=1\n"];
        yield 'literal number' => ['1 + 2', "a: 1\n", "3\n"];
        yield 'union' => ['.a, .b', "a: 1\nb: 2\n", "1\n2\n"];
        yield 'length' => ['.a | length', "a: hello\n", "5\n"];
        yield 'keys' => ['keys', "b: 1\na: 2\n", "- b\n- a\n"];
    }

    public function testSlicingAMappingIsAnError(): void
    {
        try {
            YqHarness::run('.[1:3]', "n: 1\n");
        } catch (EvaluationException $evaluationException) {
            self::assertSame('Cannot index !!map with a slice', $evaluationException->getMessage());

            return;
        }

        self::fail('expected an evaluation error');
    }

    public function testNullInputProgram(): void
    {
        self::assertSame("3\n", YqHarness::run('1 + 2', nullInput: true));
    }
}
