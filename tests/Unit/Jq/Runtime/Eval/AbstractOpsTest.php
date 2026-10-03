<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use Closure;
use LTS\PhpXq\Jq\Runtime\Eval\AbstractOp;
use LTS\PhpXq\Jq\Runtime\Eval\AbstractSingleOp;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AbstractOp::class)]
#[CoversClass(AbstractSingleOp::class)]
final class AbstractOpsTest extends OpTestCase
{
    public function testAbstractOpPathModeReportsEveryOutputWithoutAPath(): void
    {
        $op = new class extends AbstractOp {
            public function run(?Env $env, mixed $input, Closure $emit): void
            {
                $emit(1);
                $emit(2);
            }
        };

        self::assertSame([[null, 1], [null, 2]], self::pathOutputs($op, null, ['ignored']));
    }

    public function testAbstractSingleOpDerivesRunAndPathsFromValue(): void
    {
        $op = new class extends AbstractSingleOp {
            public function value(?Env $env, mixed $input): mixed
            {
                return $input . '!';
            }
        };

        self::assertSame(['a!'], self::outputs($op, 'a'));
        self::assertSame([[null, 'a!']], self::pathOutputs($op, 'a'));
    }
}
