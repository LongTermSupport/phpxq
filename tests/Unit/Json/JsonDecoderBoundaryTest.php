<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Json;

use Generator;
use LTS\PhpXq\Json\Codec\ParseFailure;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonSyntaxException;
use LTS\PhpXq\Json\PreciseNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Boundary and position behaviour of the decoder that the broader decoder tests do not pin down.
 *
 * @internal
 */
final class JsonDecoderBoundaryTest extends TestCase
{
    private const string BOM = "\xEF\xBB\xBF";

    private const string RS = "\x1e";

    private const string PAIR_ERROR = 'Invalid \uXXXX\uXXXX surrogate pair escape at line 1, column 14';

    private const string RESYNC_ERROR = "Unmatched '}' at line 1, column 5 (need RS to resync)";

    private const string TRUNCATED_NUMBER = 'Potentially truncated top-level numeric value';

    private const string TRUNCATED_VALUE = 'Truncated value';

    private const string ONE = 'int:1';

    private const string TWO = 'int:2';

    private const string THREE = 'int:3';

    private const string ARRAY_ONE = '[int:1]';

    /**
     * Errors are reported at the byte after the offending character, so text after it must not shift the
     * column.
     */
    #[DataProvider('trailingTextErrorProvider')]
    public function testErrorColumnIgnoresTextAfterTheError(string $json, string $message): void
    {
        self::assertSame($message, $this->failure(static fn (): mixed => iterator_to_array(new JsonDecoder()->decodeAll($json), false)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trailingTextErrorProvider(): iterable
    {
        yield 'separator after literal' => ['[1 2] ab', 'Expected separator between values at line 1, column 5'];
        yield 'unmatched bracket'       => ['] ab', "Unmatched ']' at line 1, column 1"];
        yield 'trailing array comma'    => ['[1,] ab', 'Expected another array element at line 1, column 4'];
        yield 'unmatched brace'         => ['} ab', "Unmatched '}' at line 1, column 1"];
        yield 'key without value'       => ['{"a"} ab', 'Objects must consist of key:value pairs at line 1, column 5'];
        yield 'trailing object comma'   => ['{"a":1,} ab', 'Expected another key-value pair at line 1, column 8'];
        yield 'bad escape'              => ['"\x" ab', 'Invalid escape at line 1, column 4'];
        yield 'control character'       => ["\"\\n\x01\" ab", 'Invalid string: control characters from U+0000 through U+001F must be escaped at line 1, column 5'];
        yield 'short unicode escape'    => ['"\u123" ab', 'Invalid \uXXXX escape at line 1, column 7'];
        yield 'lone high surrogate'     => ['"\ud800" ab', 'Invalid \uXXXX\uXXXX surrogate pair escape at line 1, column 8'];
        yield 'high then plain letter'  => ['"\ud800Audc00" ab', self::PAIR_ERROR];
        yield 'high then bad escape'    => ['"\ud800\xdc00" ab', self::PAIR_ERROR];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function surrogateProvider(): iterable
    {
        yield 'highest pair'          => ['"\udbff\udfff"', "\u{10ffff}"];
        yield 'lowest pair'           => ['"\ud800\udc00"', "\u{10000}"];
        yield 'upper low bound'       => ['"\ud800\udfff"', "\u{103ff}"];
        yield 'pair then hex digit'   => ['"\ud83d\ude00a"', "\u{1f600}a"];
        yield 'pair then escape'      => ['"\ud83d\ude00\n"', "\u{1f600}\n"];
        yield 'two pairs'             => ['"\ud83d\ude00\ud83d\ude01"', "\u{1f600}\u{1f601}"];
        yield 'highest bmp escape'    => ['"\uffff"', "\u{ffff}"];
        yield 'last before surrogate' => ['"\ud7ffz"', "\u{d7ff}z"];
    }

    #[DataProvider('surrogateProvider')]
    public function testSurrogateEscapes(string $json, string $expected): void
    {
        self::assertSame($expected, new JsonDecoder()->decodeOne($json));

        $method = new ReflectionMethod(JsonDecoder::class, 'scan');
        $values = $method->invoke(new JsonDecoder(), $json, false, 0);
        self::assertInstanceOf(Generator::class, $values);
        self::assertSame([$expected], iterator_to_array($values, false));
    }

    public function testStringWithTrailingBackslashIsRejected(): void
    {
        $method = new ReflectionMethod(JsonDecoder::class, 'string');

        try {
            $method->invoke(new JsonDecoder(), 'a\\', 5);
            self::fail('expected a parse failure');
        } catch (ParseFailure $parseFailure) {
            self::assertSame('Expected escape character at end of string', $parseFailure->getMessage());
            self::assertSame(5, $parseFailure->consumed);
        }
    }

    public function testTryDecodeOneKeepsTextWithoutABomIntact(): void
    {
        $ok = false;

        self::assertSame(12345, new JsonDecoder()->tryDecodeOne('12345', $ok));
        self::assertTrue($ok);

        $ok = false;
        self::assertSame('abcdef', new JsonDecoder()->tryDecodeOne('"abcdef"', $ok));
        self::assertTrue($ok);
    }

    public function testTryDecodeOneStripsTheBom(): void
    {
        $ok = false;

        self::assertSame(12, new JsonDecoder()->tryDecodeOne(self::BOM . '12', $ok));
        self::assertTrue($ok);

        $ok    = false;
        $value = new JsonDecoder()->tryDecodeOne(self::BOM . '1.0', $ok);
        self::assertTrue($ok);
        self::assertInstanceOf(PreciseNumber::class, $value);
        self::assertSame('1.0', $value->literal);
    }

    public function testTryDecodeOneAcceptsSingleValueNeedingTheScanner(): void
    {
        $ok    = false;
        $value = new JsonDecoder()->tryDecodeOne('"\u0000"', $ok);

        self::assertTrue($ok);
        self::assertSame("\x00", $value);

        $ok    = false;
        $value = new JsonDecoder()->tryDecodeOne('[1.0, "\u0000"]', $ok);

        self::assertTrue($ok);
        self::assertIsArray($value);
        self::assertSame("\x00", $value[1]);
    }

    public function testTryDecodeOneRejectsMalformedBomAndExtraValues(): void
    {
        $ok = true;
        self::assertNull(new JsonDecoder()->tryDecodeOne("\xEF\xBB[1]", $ok));
        self::assertFalse($ok);

        $ok = true;
        self::assertNull(new JsonDecoder()->tryDecodeOne('[1.0, "\u0000"] 2', $ok));
        self::assertFalse($ok);
    }

    public function testMalformedBomOnTheFirstLineIsReportedForMultiLineText(): void
    {
        self::assertSame('Malformed BOM', $this->failure(static fn (): mixed => iterator_to_array(new JsonDecoder()->decodeAll("\xEF\xBB[1]\n2.0\n"), false)));
    }

    /**
     * @param list<string> $values
     * @param list<string> $errors
     */
    #[DataProvider('seqProvider')]
    public function testSequenceModeBoundaries(string $text, array $values, array $errors): void
    {
        $gotValues = [];
        $gotErrors = [];
        foreach (new JsonDecoder()->decodeAll($text, true) as $item) {
            if ($item instanceof JsonSyntaxException) {
                $gotErrors[] = $item->getMessage();

                continue;
            }

            $gotValues[] = $this->describe($item);
        }

        self::assertSame($values, $gotValues);
        self::assertSame($errors, $gotErrors);
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function seqProvider(): iterable
    {
        $rs  = self::RS;
        $big = '12345678901234567890';

        yield 'truncated literal'            => [$rs . 'tru' . $rs . "3\n", [self::THREE], [self::TRUNCATED_VALUE . ' at line 1, column 5']];
        yield 'truncated float'              => [$rs . '1.5' . $rs . "3\n", [self::THREE], [self::TRUNCATED_NUMBER . ' at line 1, column 5']];
        yield 'truncated big number'         => [$rs . $big . $rs . "3\n", [self::THREE], [self::TRUNCATED_NUMBER . ' at line 1, column 22']];
        yield 'number after space at rs'     => [$rs . '1 2' . $rs, [self::ONE, self::TWO], []];
        yield 'bad literal before bracket'   => [$rs . '[tru]' . $rs . "2\n", [self::TWO], ['Invalid literal at line 1, column 6 (need RS to resync)']];
        yield 'bad literal before space'     => [$rs . 'tru ' . $rs . "2\n", [self::TWO], ['Invalid literal at line 1, column 5 (need RS to resync)']];
        yield 'empty record'                 =>[$rs . $rs . "[1]\n", [self::ARRAY_ONE], []];
        yield 'array directly before rs'     => [$rs . '[1]' . $rs . '[2]', [self::ARRAY_ONE, '[int:2]'], []];
        yield 'numbers end at rs after ws'   => [$rs . '1 ' . $rs . '2 ' . $rs, [self::ONE, self::TWO], []];
        yield 'open array is dropped at rs'  => [$rs . '[1 ' . $rs . '2 ' . $rs, [self::TWO], []];
        yield 'text after error is skipped'  => [$rs . '[1,}xyz' . $rs . "2\n", [self::TWO], [self::RESYNC_ERROR]];
        yield 'blank after error'            => [$rs . "[1,}\n", [], [self::RESYNC_ERROR]];
        yield 'spaces after error'           => [$rs . '[1,}  ', [], [self::RESYNC_ERROR]];
        yield 'float at eof'                 => [$rs . '1.5', [], [self::TRUNCATED_NUMBER . ' at EOF at line 1, column 4']];
        yield 'big number at eof'            => [$rs . $big, [], [self::TRUNCATED_NUMBER . ' at EOF at line 1, column 21']];
        yield 'rs inside string at start'    => [$rs . '"' . $rs . "[1]\n", [self::ARRAY_ONE], []];
        yield 'rs inside string after text'  => [$rs . '"a' . $rs . "1\n", [self::ONE], [self::TRUNCATED_VALUE . ' at line 1, column 4']];
    }

    private function describe(mixed $value): string
    {
        if (\is_int($value)) {
            return 'int:' . $value;
        }

        if (\is_array($value)) {
            $parts = [];
            foreach ($value as $member) {
                $parts[] = $this->describe($member);
            }

            return '[' . implode(',', $parts) . ']';
        }

        self::assertIsString($value);

        return 'string:' . $value;
    }

    /**
     * @param callable(): mixed $call
     */
    private function failure(callable $call): string
    {
        try {
            $call();
        } catch (JsonSyntaxException $jsonSyntaxException) {
            return $jsonSyntaxException->getMessage();
        }

        return 'no exception';
    }
}
