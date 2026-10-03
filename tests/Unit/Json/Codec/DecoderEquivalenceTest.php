<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json\Codec;

use Generator;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonObject;
use LTS\PhpXq\Json\PreciseNumber;
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
        mt_srand(2024);
        $decoder = new JsonDecoder();
        for ($i = 0; $i < 1500; ++$i) {
            $text = self::randomValue(0);
            $fast = self::describe($decoder->decodeOne($text));
            $slow = $this->scanned($text);

            self::assertCount(1, $slow, $text);
            self::assertSame($fast, self::describe($slow[0]), $text);
        }
    }

    public function testRandomStreamsAgree(): void
    {
        mt_srand(99);
        $decoder = new JsonDecoder();
        for ($i = 0; $i < 300; ++$i) {
            $lines = [];
            $count = mt_rand(1, 8);
            for ($k = 0; $k < $count; ++$k) {
                $lines[] = self::randomValue(1);
            }

            $text = implode(0 === $i % 3 ? "\r\n" : "\n", $lines);
            $text .= 0 === $i % 2 ? "\n" : '';

            $fast = array_map(self::describe(...), iterator_to_array($decoder->decodeAll($text), false));
            $slow = array_map(self::describe(...), $this->scanned($text));

            self::assertCount($count, $fast, $text);
            self::assertSame($fast, $slow, $text);
        }
    }

    /**
     * The hand written scanner on its own, bypassing the native fast paths.
     *
     * @return list<mixed>
     */
    private function scanned(string $text): array
    {
        $method = new ReflectionMethod(JsonDecoder::class, 'scan');
        $values = $method->invoke(new JsonDecoder(), $text, false, 0);
        self::assertInstanceOf(Generator::class, $values);

        return iterator_to_array($values, false);
    }

    private static function randomValue(int $depth): string
    {
        $kind = mt_rand(0, $depth > 3 ? 5 : 9);
        if ($kind <= 1) {
            return self::NUMBERS[mt_rand(0, \count(self::NUMBERS) - 1)];
        }

        if ($kind <= 3) {
            return self::STRINGS[mt_rand(0, \count(self::STRINGS) - 1)];
        }

        if (4 === $kind) {
            return ['true', 'false', 'null'][mt_rand(0, 2)];
        }

        if (5 === $kind) {
            return 0 === mt_rand(0, 1) ? '[]' : '{}';
        }

        $space = ['', ' ', "\t", "\n  "][mt_rand(0, 3)];
        $count = mt_rand(0, 4);
        if ($kind <= 7) {
            $items = [];
            for ($k = 0; $k < $count; ++$k) {
                $items[] = $space . self::randomValue($depth + 1) . $space;
            }

            return '[' . implode(',', $items) . ']';
        }

        $keys  = ['a', 'b', 'c', '1', '01', '', 'a b', "k\u{e9}", 'a'];
        $items = [];
        for ($k = 0; $k < $count; ++$k) {
            $items[] = $space . '"' . $keys[mt_rand(0, \count($keys) - 1)] . '"' . $space . ':' . $space . self::randomValue($depth + 1) . $space;
        }

        return '{' . implode(',', $items) . '}';
    }

    private static function describe(mixed $value): string
    {
        if (null === $value || \is_bool($value)) {
            return json_encode($value, \JSON_THROW_ON_ERROR);
        }

        if (\is_int($value)) {
            return 'i' . $value;
        }

        if (\is_float($value)) {
            return 'f' . var_export($value, true);
        }

        if ($value instanceof PreciseNumber) {
            return 'p' . $value->literal;
        }

        if (\is_string($value)) {
            return 's' . bin2hex($value);
        }

        if (\is_array($value)) {
            return '[' . implode(',', array_map(self::describe(...), $value)) . ']';
        }

        self::assertInstanceOf(JsonObject::class, $value);
        $parts = [];
        foreach ($value->entries() as $key => $member) {
            $parts[] = bin2hex($key) . '=' . self::describe($member);
        }

        return '{' . implode(',', $parts) . '}';
    }
}
