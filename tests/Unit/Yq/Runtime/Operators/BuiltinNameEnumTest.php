<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Yq\Runtime\Operators\BuiltinNameEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BuiltinNameEnum::class)]
final class BuiltinNameEnumTest extends TestCase
{
    public function testEveryCaseValueIsUnique(): void
    {
        $values = array_map(static fn (BuiltinNameEnum $case): string => $case->value, BuiltinNameEnum::cases());

        self::assertSame($values, array_values(array_unique($values)));
    }

    public function testValuesListsTheSpellingsInTheOrderGiven(): void
    {
        self::assertSame(['setpath', 'del'], BuiltinNameEnum::values(BuiltinNameEnum::Setpath, BuiltinNameEnum::Del));
        self::assertSame([], BuiltinNameEnum::values());
    }

    #[DataProvider('names')]
    public function testANameResolvesToItsCaseOrNull(string $name, ?BuiltinNameEnum $expected): void
    {
        self::assertSame($expected, BuiltinNameEnum::tryFrom($name));
    }

    /**
     * @return iterable<string, array{string, ?BuiltinNameEnum}>
     */
    public static function names(): iterable
    {
        yield 'unknown' => ['no_such_builtin', null];

        yield 'camel case alias' => ['splitDoc', BuiltinNameEnum::SplitDocCamel];
    }
}
