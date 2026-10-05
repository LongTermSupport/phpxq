<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime;

use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\ProgramHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runaway jq recursion must end in a jq error, never in a native stack overflow (a segfault under the default
 * 8 MB stack, which Xdebug coverage mode makes much easier to hit).
 *
 * @internal
 */
final class RecursionGuardTest extends TestCase
{
    private const string TOO_DEEP = 'Evaluation too deep';

    private const string NULL_INPUT = 'null';

    /**
     * @return iterable<string, array{string}>
     */
    public static function runawayPrograms(): iterable
    {
        yield 'plain recursion'          => ['def f: 1 + f; f'];
        yield 'tail position recursion'  => ['def f: f; f'];
        yield 'recursion in a pipe'      => ['def f: . | f; f'];
        yield 'closure parameter'        => ['def f(g): g | f(g); f(.)'];
        yield 'nested definition'        => ['def f: def g: g; g; f'];
        yield 'mutual recursion'         => ['def f: def g: f; g; f'];
        yield 'recursion through reduce' => ['def f: reduce 1 as $x (.; f); f'];
        yield 'recursion through paths'  => ['def f: path(f); f'];
        yield 'recursion through try'    => ['def f: try f catch error; f'];
        yield 'recursion in generator'   => ['def f: (1, f); [f]'];
        yield 'recursion through map'    => ['def f: [1] | map(f); f'];
    }

    #[DataProvider('runawayPrograms')]
    public function testRunawayRecursionFailsWithAJqError(string $program): void
    {
        $message = ProgramHarness::error($program, self::NULL_INPUT);

        self::assertSame(self::TOO_DEEP, $message);
    }

    public function testEvaluationStillWorksAfterTheGuardTripped(): void
    {
        self::assertSame(self::TOO_DEEP, ProgramHarness::error('def f: 1 + f; f', self::NULL_INPUT));
        self::assertSame(['10000'], ProgramHarness::outputs('def f: if . < 10000 then . + 1 | f else . end; 0 | f'));
    }

    public function testHeavyBodiesAtTheDepthLimitStayOnTheNativeStack(): void
    {
        $program = 'def f: if . < 9000 then (reduce (1) as $x (.; . + 1)) | [foreach (1) as $y (.; .; .)][0] | . as $s | path(.) as $p | f else . end; 0 | f';

        self::assertSame(['9000'], ProgramHarness::outputs($program));
    }
}
