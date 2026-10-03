<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\BoundFilter;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\VarOp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundFilter::class)]
final class BoundFilterTest extends TestCase
{
    public function testRunsTheOpInItsEnvironment(): void
    {
        $filter  = new BoundFilter(new VarOp(0), new Env(null, 'bound'));
        $outputs = [];
        $filter->run('ignored', static function (mixed $value) use (&$outputs): void {
            $outputs[] = $value;
        });

        self::assertSame(['bound'], $outputs);
    }

    public function testPathModeDelegates(): void
    {
        $filter  = new BoundFilter(new VarOp(0), new Env(null, 'bound'));
        $outputs = [];
        $filter->paths(['p'], 'ignored', static function (?array $path, mixed $value) use (&$outputs): void {
            $outputs[] = [$path, $value];
        });

        self::assertSame([[null, 'bound']], $outputs);
    }
}
