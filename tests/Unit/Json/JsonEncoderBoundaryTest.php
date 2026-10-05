<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use InvalidArgumentException;
use LTS\PhpXq\Json\ColorScheme;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonEncoder;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Depth limit of the coloured printer, colour selection and ascii escaping boundaries.
 *
 * @internal
 */
final class JsonEncoderBoundaryTest extends TestCase
{
    private const string SKIPPED = '<skipped: too deep>';

    #[DataProvider('depthProvider')]
    public function testColouredArraysSkipBeyondTheDepthLimit(int $levels, bool $skipped): void
    {
        $value = 1;
        for ($i = 0; $i < $levels; ++$i) {
            $value = [$value];
        }

        $text = new JsonEncoder()->encode($value, new EncodeOptions(indent: 0, colors: ColorScheme::default()));

        self::assertSame($skipped, str_contains($text, self::SKIPPED));
    }

    #[DataProvider('depthProvider')]
    public function testColouredObjectsSkipBeyondTheDepthLimit(int $levels, bool $skipped): void
    {
        $value = 1;
        for ($i = 0; $i < $levels; ++$i) {
            $value = JsonObject::fromPairs(['a' => $value]);
        }

        $text = new JsonEncoder()->encode($value, new EncodeOptions(indent: 0, colors: ColorScheme::default()));

        self::assertSame($skipped, str_contains($text, self::SKIPPED));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function depthProvider(): iterable
    {
        yield 'at the limit'   => [10000, false];
        yield 'one beyond'     => [10001, true];
    }

    public function testColouredOutputKeepsTheLeafAtTheLimit(): void
    {
        $value = 7;
        for ($i = 0; $i < 10000; ++$i) {
            $value = [$value];
        }

        $text = new JsonEncoder()->encode($value, new EncodeOptions(indent: 0, colors: ColorScheme::default()));

        self::assertStringContainsString("\e[0;39m7\e[0m", $text);
    }

    public function testEveryValueKindUsesItsOwnColour(): void
    {
        $colors = new ColorScheme("\e[0;30m", "\e[0;31m", "\e[0;32m", "\e[0;33m", "\e[0;34m", "\e[1;35m", "\e[1;36m", "\e[1;37m");
        $text   = new JsonEncoder()->encode([null, false, true, 1, 'x'], new EncodeOptions(indent: 0, colors: $colors));

        self::assertSame(
            "\e[1;35m[\e[0m"
            . "\e[0;30mnull\e[0m\e[1;35m,\e[0m"
            . "\e[0;31mfalse\e[0m\e[1;35m,\e[0m"
            . "\e[0;32mtrue\e[0m\e[1;35m,\e[0m"
            . "\e[0;33m1\e[0m\e[1;35m,\e[0m"
            . "\e[0;34m\"x\"\e[0m"
            . "\e[1;35m]\e[0m",
            $text,
        );
    }

    public function testRejectsValuesOutsideTheModelWithTheirType(): void
    {
        try {
            new JsonEncoder()->encode(new stdClass(), EncodeOptions::compact());
            self::fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertSame('Not a JSON value: stdClass', $invalidArgumentException->getMessage());
        }
    }

    /**
     * @param list<string> $units the lower case hex of each UTF-16 code unit
     */
    #[DataProvider('asciiProvider')]
    public function testAsciiEscapesOfSupplementaryCharacters(string $text, array $units): void
    {
        $expected = '"' . implode('', array_map(static fn (string $unit): string => '\\u' . $unit, $units)) . '"';

        self::assertSame($expected, new JsonEncoder()->encode($text, new EncodeOptions(indent: 0, ascii: true)));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function asciiProvider(): iterable
    {
        yield 'lowest supplementary'  => ["\u{10000}", ['d800', 'dc00']];
        yield 'highest bmp'           => ["\u{ffff}", ['ffff']];
        yield 'odd low surrogate'     => ["\u{1f601}", ['d83d', 'de01']];
        yield 'highest supplementary' => ["\u{10ffff}", ['dbff', 'dfff']];
        yield 'last low bit set'      => ["\u{1ffff}", ['d83f', 'dfff']];
    }
}
