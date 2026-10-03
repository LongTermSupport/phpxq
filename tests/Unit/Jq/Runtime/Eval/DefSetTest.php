<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Runtime\Eval;

use LTS\PhpXq\Jq\Ast\FuncDef;
use LTS\PhpXq\Jq\Ast\Identity;
use LTS\PhpXq\Jq\Runtime\Eval\DefSet;
use LTS\PhpXq\Jq\Runtime\Eval\FuncInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefSet::class)]
final class DefSetTest extends TestCase
{
    public function testFindsTheLatestDefinitionBelowTheLimit(): void
    {
        $set   = new DefSet();
        $first = self::define($set, 'f');
        $last  = self::define($set, 'f');

        self::assertSame($last, $set->find('f', 0, \PHP_INT_MAX));
        self::assertSame($first, $set->find('f', 0, 1));
        self::assertNull($set->find('f', 0, 0));
        self::assertNull($set->find('f', 1, \PHP_INT_MAX));
        self::assertSame(2, $set->size());
        self::assertSame([$first, $last], $set->functions());
    }

    public function testIncludesComeAfterOwnDefinitionsAndLatestWins(): void
    {
        $firstInclude  = new DefSet();
        $secondInclude = new DefSet();
        $fromFirst     = self::define($firstInclude, 'e');
        $fromSecond    = self::define($secondInclude, 'e');
        $onlyFirst     = self::define($firstInclude, 'g');

        $set = new DefSet();
        $set->include($firstInclude);
        $set->include($secondInclude);

        self::assertSame($fromSecond, $set->find('e', 0, \PHP_INT_MAX));
        self::assertSame($onlyFirst, $set->find('g', 0, \PHP_INT_MAX));

        $own = self::define($set, 'e');
        self::assertSame($own, $set->find('e', 0, \PHP_INT_MAX));
        self::assertSame($fromSecond, $set->find('e', 0, 0));
        self::assertNotSame($fromFirst, $set->find('e', 0, \PHP_INT_MAX));
    }

    public function testParentIsConsultedLast(): void
    {
        $parent = new DefSet();
        $inner  = self::define($parent, 'map');
        $set    = new DefSet($parent);

        self::assertSame($inner, $set->find('map', 0, \PHP_INT_MAX));

        $shadow = self::define($set, 'map');
        self::assertSame($shadow, $set->find('map', 0, \PHP_INT_MAX));
    }

    public function testAliasedLookupPrefersTheLatestImport(): void
    {
        $one = new DefSet();
        $two = new DefSet();
        self::define($one, 'sym0');
        self::define($one, 'sym1');
        $oneSym1 = self::define($one, 'sym1');
        $twoSym1 = self::define($two, 'sym1');
        $two->include(new DefSet());

        $set = new DefSet();
        $set->alias('t', $one);
        $set->alias('t', $two);

        self::assertSame($twoSym1, $set->findAliased('t', 'sym1', 0));
        self::assertNotNull($set->findAliased('t', 'sym0', 0));
        self::assertNotSame($oneSym1, $set->findAliased('t', 'sym1', 0));
        self::assertNull($set->findAliased('t', 'missing', 0));
        self::assertNull($set->findAliased('other', 'sym0', 0));
    }

    public function testAliasedLookupDoesNotFallBackToTheParent(): void
    {
        $parent = new DefSet();
        self::define($parent, 'f');
        $module = new DefSet($parent);
        $set    = new DefSet();
        $set->alias('m', $module);

        self::assertNull($set->findAliased('m', 'f', 0));
    }

    public function testDataImports(): void
    {
        $set = new DefSet();
        $set->addData('d', [1, 2]);

        self::assertSame([1, 2], $set->data('d'));
        self::assertNull($set->data('other'));
    }

    private static function define(DefSet $set, string $name): FuncInfo
    {
        $info = new FuncInfo(new FuncDef($name, [], new Identity()), $set, $set->size() + 1);
        $set->add($info);

        return $info;
    }
}
