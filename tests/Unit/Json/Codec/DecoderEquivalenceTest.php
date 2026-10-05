<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json\Codec;

use Generator;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\PreciseNumber;
use LTS\PhpXq\Tests\Support\JsonValueDescriber;
use LTS\PhpXq\Tests\Support\SeededRandom;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Random documents decoded through the native fast paths and through the hand written scanner must
 * produce identical values, types and preserved literals.
 *
 * @internal
 */
final class DecoderEquivalenceTest extends TestCase
{
    private const array NUMBERS = [
        '0', '-0', '1', '-1', '42', '123456789012345', '1234567890123456', '9007199254740992', '9007199254740993',
        '12345678909876543212345', '1.5', '-2.25', '0.1', '0.5', '1.0', '1.50', '10.05', '100.0', '0.0', '-0.0',
        '1e5', '1E5', '1e-5', '1.5e300', '1E+2', '0.0001', '0.00001', '0.00012', '123456.789', '3.14159265358979',
        '0.30000000000000004', '0.12345678901234567890123456789', '1e1000', '5e-324', '99.99', '12.5', '7.07',
    ];

    private const array STRINGS = [
        '""', '"a"', '"abc def"', '"quote \" and slash \\\ solidus \/"', '"tab \t nl \n cr \r bs \b ff \f"',
        '"\u00e9\u20ac"', '"\ud83d\ude00"', '"\u0000"', '"\u007f"', "\"\u{e9}\u{1f600}\"", '"{[,:]}"', '"1e5"',
        '"-0"', '"\udc00"',
    ];

    public function testRandomDocumentsAgree(): void
    {
        $random  = new SeededRandom(2024);
        $decoder = new JsonDecoder();
        for ($i = 0; $i < 1500; ++$i) {
            $text = self::randomValue($random, 0);
            $fast = $this->describe($decoder->decodeOne($text));
            $slow = $this->scanned($decoder, $text);

            self::assertCount(1, $slow, $text);
            self::assertSame($fast, $this->describe($slow[0]), $text);
        }
    }

    public function testRandomStreamsAgree(): void
    {
        $random  = new SeededRandom(99);
        $decoder = new JsonDecoder();
        for ($i = 0; $i < 300; ++$i) {
            $lines = [];
            $count = $random->between(1, 8);
            for ($k = 0; $k < $count; ++$k) {
                $lines[] = self::randomValue($random, 1);
            }

            $text = implode(0 === $i % 3 ? "\r\n" : "\n", $lines);
            $text .= 0 === $i % 2 ? "\n" : '';

            $fast = array_map($this->describe(...), iterator_to_array($decoder->decodeAll($text), false));
            $slow = array_map($this->describe(...), $this->scanned($decoder, $text));

            self::assertCount($count, $fast, $text);
            self::assertSame($fast, $slow, $text);
        }
    }

    /**
     * The hand written scanner on its own, bypassing the native fast paths.
     *
     * @return list<mixed>
     */
    private function scanned(JsonDecoder $decoder, string $text): array
    {
        $method = new ReflectionMethod(JsonDecoder::class, 'scan');
        $values = $method->invoke($decoder, $text, false, 0);
        self::assertInstanceOf(Generator::class, $values);

        return iterator_to_array($values, false);
    }

    private static function randomValue(SeededRandom $random, int $depth): string
    {
        $kind = $random->between(0, $depth > 3 ? 5 : 9);
        if ($kind <= 1) {
            return $random->pick(...self::NUMBERS);
        }

        if ($kind <= 3) {
            return $random->pick(...self::STRINGS);
        }

        if (4 === $kind) {
            return $random->pick('true', 'false', 'null');
        }

        if (5 === $kind) {
            return 0 === $random->between(0, 1) ? '[]' : '{}';
        }

        $space = $random->pick('', ' ', "\t", "\n  ");
        $count = $random->between(0, 4);
        if ($kind <= 7) {
            $items = [];
            for ($k = 0; $k < $count; ++$k) {
                $items[] = $space . self::randomValue($random, $depth + 1) . $space;
            }

            return '[' . implode(',', $items) . ']';
        }

        $keys  = ['a', 'b', 'c', '1', '01', '', 'a b', "k\u{e9}", 'a'];
        $items = [];
        for ($k = 0; $k < $count; ++$k) {
            $items[] = $space . '"' . $random->pick(...$keys) . '"' . $space . ':' . $space . self::randomValue($random, $depth + 1) . $space;
        }

        return '{' . implode(',', $items) . '}';
    }

    private function describe(mixed $value): string
    {
        return new JsonValueDescriber(
            static function (mixed $scalar): string {
                if (null === $scalar || \is_bool($scalar)) {
                    return json_encode($scalar, \JSON_THROW_ON_ERROR);
                }

                if (\is_int($scalar)) {
                    return 'i' . $scalar;
                }

                if (\is_float($scalar)) {
                    return 'f' . var_export($scalar, true);
                }

                if ($scalar instanceof PreciseNumber) {
                    return 'p' . $scalar->literal;
                }

                self::assertIsString($scalar);

                return 's' . bin2hex($scalar);
            },
            static fn (string $key, string $member): string => bin2hex($key) . '=' . $member,
        )->describe($value);
    }
}
