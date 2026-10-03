<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Runtime\Eval\ArrayBinder;
use LTS\PhpXq\Jq\Runtime\Eval\Binder;
use LTS\PhpXq\Jq\Runtime\Eval\Env;
use LTS\PhpXq\Jq\Runtime\Eval\ObjectBinder;
use LTS\PhpXq\Jq\Runtime\Eval\VarBinder;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\AssertsRaised;
use LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval\Support\OpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(Binder::class)]
#[CoversClass(VarBinder::class)]
#[CoversClass(ArrayBinder::class)]
#[CoversClass(ObjectBinder::class)]
final class BinderTest extends OpTestCase
{
    use AssertsRaised;

    public function testVarBinderPushesTheValue(): void
    {
        $bound = self::bindAll(new VarBinder(), 5, new Env(null, 'outer'));

        self::assertCount(1, $bound);
        self::assertSame([5, 'outer'], $this->values($bound[0], 2));
    }

    public function testArrayBinderPushesOneEntryPerElementInOrder(): void
    {
        $binder = new ArrayBinder([new VarBinder(), new VarBinder()]);

        $bound = self::bindAll($binder, [1, 2]);

        self::assertSame([2, 1], $this->values($bound[0], 2));
    }

    public function testArrayBinderBindsNullForMissingElementsAndNullInput(): void
    {
        $binder = new ArrayBinder([new VarBinder(), new VarBinder()]);

        self::assertSame([null, 1], $this->values(self::bindAll($binder, [1])[0], 2));
        self::assertSame([null, null], $this->values(self::bindAll($binder, null)[0], 2));
    }

    public function testArrayBinderRejectsObjects(): void
    {
        self::assertRaises(JqException::class, 'Cannot index object with number (0)', static fn (): mixed => self::bindAll(new ArrayBinder([new VarBinder()]), self::object(['a' => 1])));
    }

    public function testObjectBinderWithVariableKeys(): void
    {
        $binder = new ObjectBinder([['a', null, null], ['b', null, null]]);

        $bound = self::bindAll($binder, self::object(['a' => 1, 'b' => 2]));

        self::assertSame([2, 1], $this->values($bound[0], 2));
    }

    public function testObjectBinderBindsTheMemberAndItsSubPattern(): void
    {
        $binder = new ObjectBinder([['all', null, new ArrayBinder([new VarBinder()])]]);
        $bound  = self::bindAll($binder, self::object(['all' => [7]]));

        self::assertSame([7, [7]], $this->values($bound[0], 2));
    }

    public function testObjectBinderWithKeyExpressionAndNoVariable(): void
    {
        $binder = new ObjectBinder([[null, self::generator(['a', 'b']), new VarBinder()]]);

        $bound = self::bindAll($binder, self::object(['a' => 1, 'b' => 2]));

        self::assertCount(2, $bound);
        self::assertSame([1], $this->values($bound[0], 1));
        self::assertSame([2], $this->values($bound[1], 1));
    }

    public function testObjectBinderKeyExpressionsSeeEarlierVariables(): void
    {
        $binder = new ObjectBinder([
            ['k', null, null],
            [null, new \LTS\PhpXq\Jq\Runtime\Eval\VarOp(0), new VarBinder()],
        ]);

        $bound = self::bindAll($binder, self::object(['k' => 'name', 'name' => 'found']));

        self::assertSame(['found', 'name'], $this->values($bound[0], 2));
    }

    public function testObjectBinderRejectsNonObjects(): void
    {
        $this->expectException(JqException::class);

        self::bindAll(new ObjectBinder([['a', null, null]]), [1]);
    }

    /**
     * @return list<?Env>
     */
    private static function bindAll(Binder $binder, mixed $value, ?Env $base = null): array
    {
        $results = [];
        $binder->bind($base, $value, static function (?Env $env) use (&$results): void {
            $results[] = $env;
        });

        return $results;
    }

    /**
     * The values of the innermost $count entries, innermost first.
     *
     * @return list<mixed>
     */
    private function values(?Env $env, int $count): array
    {
        $values = [];
        for ($i = 0; $i < $count; ++$i) {
            $values[] = $env?->value;
            $env      = $env?->parent;
        }

        return $values;
    }
}
