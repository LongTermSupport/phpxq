<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\FieldOp;
use LTS\PhpXq\Jq\Runtime\Eval\IterateOp;
use LTS\PhpXq\Jq\Runtime\Eval\PathOp;
use LTS\PhpXq\Jq\Runtime\Eval\PipeOp;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PathOp::class)]
final class PathOpTest extends OpTestCase
{
    public function testEmitsThePathOfEveryOutput(): void
    {
        $op = new PathOp(new PipeOp(new FieldOp('a'), new IterateOp(null)));

        self::assertSame([['a', 0], ['a', 1]], self::outputs($op, self::object(['a' => [1, 2]])));
    }

    public function testRejectsComputedValues(): void
    {
        $this->expectException(JqException::class);
        $this->expectExceptionMessage('Invalid path expression with result 3');

        self::outputs(new PathOp(self::constant(3)));
    }

    public function testItselfProducesComputedValuesInPathMode(): void
    {
        self::assertSame([[null, []]], self::pathOutputs(new PathOp(self::identity()), 1));
    }
}
