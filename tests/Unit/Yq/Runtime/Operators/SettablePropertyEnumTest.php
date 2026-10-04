<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Yq\Runtime\Operators\SettablePropertyEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettablePropertyEnum::class)]
final class SettablePropertyEnumTest extends TestCase
{
    #[DataProvider('names')]
    public function testTheValuesAreTheNamesUsedInTheAssignmentTable(string $name, ?SettablePropertyEnum $expected): void
    {
        self::assertSame($expected, SettablePropertyEnum::tryFrom($name));
    }

    /**
     * @return iterable<string, array{string, ?SettablePropertyEnum}>
     */
    public static function names(): iterable
    {
        yield 'head' => ['head', SettablePropertyEnum::Head];

        yield 'comments' => ['comments', SettablePropertyEnum::Comments];

        yield 'unknown' => ['no_such_property', null];
    }
}
