<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Yq\Runtime\DollarTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DollarTemplate::class)]
final class DollarTemplateTest extends TestCase
{
    public function testBareNamesAndBracedBodiesAreHandedToTheirResolvers(): void
    {
        $out = DollarTemplate::expand(
            'a $x b ${y} c',
            static fn (string $body): string => '[' . $body . ']',
            static fn (string $name): string => '<' . $name . '>',
            false,
            false,
            false,
        );

        self::assertSame('a <x> b [y] c', $out);
    }

    public function testDoubleDollarEscapeOnlyAppliesWhenEnabled(): void
    {
        $bare   = static fn (string $name): string => '<' . $name . '>';
        $braced = static fn (string $body): string => '[' . $body . ']';

        self::assertSame('$x', DollarTemplate::expand('$$x', $braced, $bare, false, true, false));
        self::assertSame('$<x>', DollarTemplate::expand('$$x', $braced, $bare, false, false, false));
    }

    public function testNestedBracesBalanceOnlyWhenEnabled(): void
    {
        $bare   = static fn (string $name): string => $name;
        $braced = static fn (string $body): string => '[' . $body . ']';

        self::assertSame('[a${b}]', DollarTemplate::expand('${a${b}}', $braced, $bare, true, false, false));
        self::assertSame('[a${b]}', DollarTemplate::expand('${a${b}}', $braced, $bare, false, false, false));
    }

    public function testUnterminatedBraceAndStrayDollarStayLiteral(): void
    {
        $bare   = static fn (string $name): string => $name;
        $braced = static fn (string $body): string => $body;

        self::assertSame('${a', DollarTemplate::expand('${a', $braced, $bare, true, false, false));
        self::assertSame('$ 5 $', DollarTemplate::expand('$ 5 $', $braced, $bare, false, false, false));
    }

    public function testDigitNamesAreOnlyRecognisedWhenEnabled(): void
    {
        $bare   = static fn (string $name): string => '<' . $name . '>';
        $braced = static fn (string $body): string => $body;

        self::assertSame('<1x>', DollarTemplate::expand('$1x', $braced, $bare, false, false, true));
        self::assertSame('$1x', DollarTemplate::expand('$1x', $braced, $bare, false, false, false));
    }
}
