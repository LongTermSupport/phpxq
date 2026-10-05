<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yq\Runtime\Operators\StyleNameEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StyleNameEnum::class)]
final class StyleNameEnumTest extends TestCase
{
    #[DataProvider('styles')]
    public function testEveryNodeStyleButTheDefaultHasAName(NodeStyleEnum $style, ?StyleNameEnum $expected): void
    {
        self::assertSame($expected, StyleNameEnum::fromNodeStyle($style));
    }

    /**
     * @return iterable<string, array{NodeStyleEnum, ?StyleNameEnum}>
     */
    public static function styles(): iterable
    {
        yield 'double' => [NodeStyleEnum::DoubleQuoted, StyleNameEnum::Double];

        yield 'single' => [NodeStyleEnum::SingleQuoted, StyleNameEnum::Single];

        yield 'literal' => [NodeStyleEnum::Literal, StyleNameEnum::Literal];

        yield 'folded' => [NodeStyleEnum::Folded, StyleNameEnum::Folded];

        yield 'flow' => [NodeStyleEnum::Flow, StyleNameEnum::Flow];

        yield 'default' => [NodeStyleEnum::Default, null];
    }

    #[DataProvider('names')]
    public function testTheNameMapsBackToTheNodeStyle(StyleNameEnum $name, NodeStyleEnum $expected): void
    {
        self::assertSame($expected, $name->nodeStyle());
    }

    /**
     * @return iterable<string, array{StyleNameEnum, NodeStyleEnum}>
     */
    public static function names(): iterable
    {
        yield 'double' => [StyleNameEnum::Double, NodeStyleEnum::DoubleQuoted];

        yield 'tagged has no node style of its own' => [StyleNameEnum::Tagged, NodeStyleEnum::Default];
    }

    #[DataProvider('spellings')]
    public function testTheValueIsTheSpellingUsedInTheExpression(string $spelling, ?StyleNameEnum $expected): void
    {
        self::assertSame($expected, StyleNameEnum::tryFrom($spelling));
    }

    /**
     * @return iterable<string, array{string, ?StyleNameEnum}>
     */
    public static function spellings(): iterable
    {
        yield 'folded' => ['folded', StyleNameEnum::Folded];

        yield 'unknown' => ['nope', null];
    }
}
