<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Builtin\Core;

use LTS\PhpXq\Jq\Builtin\Core\Problems;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ProblemsTest extends TestCase
{
    public function testJsonIsCompact(): void
    {
        self::assertSame('{"a":[1,2]}', Problems::json(new JsonObject(['a' => [1, 2]])));
    }

    public function testShortDumpIsKept(): void
    {
        self::assertSame('"abc"', Problems::dump('abc'));
    }

    public function testLongStringKeepsItsClosingQuote(): void
    {
        self::assertSame('"very-long-long-long-long..."', Problems::dump('very-long-long-long-long-string'));
    }

    public function testLongNumberIsCutWithoutQuote(): void
    {
        self::assertSame('{"a":1,"b":2,"c":3,"d":4,"...', Problems::dump(new JsonObject(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5])));
    }

    public function testCutNeverSplitsACharacter(): void
    {
        self::assertSame('"xxxx' . str_repeat("\u{2606}", 6) . '..."', Problems::dump('xxxx' . str_repeat("\u{2606}", 8)));
    }

    public function testTypeErrorNamesTypeAndValue(): void
    {
        self::assertSame('number (5) has no keys', Problems::type(5, 'has no keys')->getMessage());
    }

    public function testTypeErrorTwoValues(): void
    {
        self::assertSame('string ("a") and number (1) cannot be added', Problems::type2('a', 1, 'cannot be added')->getMessage());
    }

    public function testIterateError(): void
    {
        self::assertSame('Cannot iterate over number (5)', Problems::iterate(5)->getMessage());
    }

    public function testIndexError(): void
    {
        self::assertSame('Cannot index number with string ("a")', Problems::index(5, 'a')->getMessage());
    }
}
