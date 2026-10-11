<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\ControlFunctions;
use LTS\PhpXq\Jq\Runtime\EvaluationStack;
use LTS\PhpXq\Tests\Support\CliRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * `until` and `while` loop without growing the stack when the update yields one value per step, as jq's
 * tail-call optimisation lets them, and keep jq's output order, error order and path behaviour when the
 * condition or the update yields several. Expected outputs are jq's.
 *
 * @internal
 */
#[CoversClass(ControlFunctions::class)]
#[Medium]
final class UntilWhileTest extends TestCase
{
    private const int ITERATIONS_PAST_THE_CALL_LIMIT = 3 * EvaluationStack::MAX_CALL_DEPTH;

    public function testUntilRunsPastTheCallDepthLimit(): void
    {
        $limit = self::ITERATIONS_PAST_THE_CALL_LIMIT;

        self::assertSame($limit . "\n", $this->jq('0 | until(. >= ' . $limit . '; . + 1)'));
    }

    public function testWhileRunsPastTheCallDepthLimit(): void
    {
        $limit = self::ITERATIONS_PAST_THE_CALL_LIMIT;

        self::assertSame($limit . "\n", $this->jq('[0 | while(. < ' . $limit . '; . + 1)] | length'));
    }

    #[DataProvider('programs')]
    public function testOutputsMatchJq(string $program, string $expected): void
    {
        self::assertSame($expected . "\n", $this->jq($program));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function programs(): iterable
    {
        yield 'until'                        => ['0 | until(. >= 3; . + 1)', '3'];
        yield 'while'                        => ['[0 | while(. < 3; . + 1)]', '[0,1,2]'];
        yield 'until with a branching update' => ['[1 | until(. > 4; . + 1, . + 2)]', '[5,6,5,5,6,5,6,5]'];
        yield 'while with a branching update' => ['[1 | while(. < 4; . + 1, . + 2)]', '[1,2,3,3]'];
        yield 'while with a branching condition' => ['[0 | while(. < 2, . < 1; . + 1)]', '[0,1,0,1]'];
        yield 'until with an empty update'   => ['[0 | until(. >= 2; empty)]', '[]'];
        yield 'while with an empty update'   => ['[0 | while(true; empty)]', '[0]'];
        yield 'an endless while under limit' => ['[limit(5; 0 | while(true; . + 1))]', '[0,1,2,3,4]'];
        yield 'outputs before an error'      => ['[0 | try until(. > 1; (. + 1, error("x"))) catch "caught"]', '[2,"caught"]'];
        yield 'break out of the loop'        => ['[label $out | 0 | until(. > 3; if . == 2 then break $out else . + 1 end)]', '[]'];
        yield 'until as a path'              => ['{"a":{"a":{"a":1}}} | [path(until(type != "object"; .a))]', '[["a","a","a"]]'];
        yield 'while as a path'              => ['[{"a":{"a":1}} | path(while(type == "object"; .a))]', '[[],["a"]]'];
        yield 'until as an update target'    => ['{"a":{"a":{"a":1}}} | until(type != "object"; .a) |= 5', '{"a":{"a":{"a":5}}}'];
    }

    public function testAnEndlesslyBranchingLoopStillFailsWithAJqError(): void
    {
        $result = new CliRunner()->run(['jq', '-nc', '[0 | until(. == 1, . == 2; . + 1)]']);

        self::assertSame("jq: error (at <unknown>): Evaluation too deep\n", $result->stderr);
    }

    private function jq(string $program): string
    {
        $result = new CliRunner()->run(['jq', '-nc', $program]);
        self::assertSame('', $result->stderr);

        return $result->stdout;
    }
}
