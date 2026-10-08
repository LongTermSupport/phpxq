<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yq\Format\Codec\LuaReader;
use LTS\PhpXq\Yq\Format\Codec\XmlReader;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\Evaluator;
use LTS\PhpXq\Yq\Runtime\Numbers;
use LTS\PhpXq\Yq\Runtime\Operators\DateCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * PHP 8.5 refuses to cast a float outside the int range, and the CLI turns that into an internal error. Every
 * number that reaches an int (slice bounds, function arguments, unix times, character references and escapes)
 * is range-checked first: clamped where a bound is meant, rejected or kept verbatim where a character is meant.
 *
 * @internal
 */
#[CoversClass(Numbers::class)]
#[CoversClass(Args::class)]
#[CoversClass(Evaluator::class)]
#[CoversClass(DateCalls::class)]
#[CoversClass(XmlReader::class)]
#[CoversClass(LuaReader::class)]
#[Small]
final class OutOfRangeNumberTest extends TestCase
{
    private const array NULL_TO_JSON = ['-n', '-o', 'json', '-I0'];

    private const array XML_TO_JSON = ['-p', 'xml', '-o', 'json', '-I0', '.'];

    private const array FROM_LUA = ['-p', 'lua', '.'];

    private const string BAD_LUA_ESCAPE = "Error: bad file '-': lua: line 1: invalid \\u escape\n";

    /**
     * @param list<string> $args
     */
    #[DataProvider('handled')]
    public function testOutOfRangeNumbersAreHandled(array $args, string $stdin, string $stdout, string $stderr): void
    {
        $result = new CliRunner()->run(['yq', ...$args], $stdin);

        self::assertSame($stderr, $result->stderr);
        self::assertSame($stdout, $result->stdout);
    }

    #[DataProvider('conversions')]
    public function testToIntTruncatesAndClamps(int|float $number, int $expected): void
    {
        self::assertSame($expected, Numbers::toInt($number));
    }

    /**
     * @return iterable<string, array{int|float, int}>
     */
    public static function conversions(): iterable
    {
        yield 'int'               => [7, 7];
        yield 'positive fraction' => [2.9, 2];
        yield 'negative fraction' => [-2.9, -2];
        yield 'above the range'   => [1e30, \PHP_INT_MAX];
        yield 'below the range'   => [-1e30, \PHP_INT_MIN];
        yield 'infinity'          => [\INF, \PHP_INT_MAX];
        yield 'minus infinity'    => [-\INF, \PHP_INT_MIN];
        yield 'not a number'      => [\NAN, 0];
    }

    /**
     * @return iterable<string, array{list<string>, string, string, string}>
     */
    public static function handled(): iterable
    {
        yield 'slice end past the int range'    => [[...self::NULL_TO_JSON, '[1,2] | .[0:1e30]'], '', "[1,2]\n", ''];
        yield 'slice start past the int range'  => [[...self::NULL_TO_JSON, '[1,2] | .[1e30:]'], '', "[]\n", ''];
        yield 'slice end below the int range'   => [[...self::NULL_TO_JSON, '[1,2] | .[0:-1e30]'], '', "[]\n", ''];
        yield 'integer argument past the range' => [['-n', '"abc" | .[1e30:]'], '', "\n", ''];
        yield 'unix time past the int range'    => [['-n', '1e30 | from_unix | length > 0'], '', "true\n", ''];
        yield 'xml reference past the range'    => [self::XML_TO_JSON, "<a>&#x99999999999999999999;</a>\n", "{\"a\":\"&#x99999999999999999999;\"}\n", ''];
        yield 'xml reference past unicode'      => [self::XML_TO_JSON, "<a>&#x110000;</a>\n", "{\"a\":\"&#x110000;\"}\n", ''];
        yield 'lua escape past unicode'         => [self::FROM_LUA, "return {a=\"\\u{110000}\"}\n", '', self::BAD_LUA_ESCAPE];
        yield 'lua escape past the int range'   => [self::FROM_LUA, "return {a=\"\\u{99999999999999999999}\"}\n", '', self::BAD_LUA_ESCAPE];
        yield 'lua escape of a surrogate'       => [self::FROM_LUA, "return {a=\"\\u{D800}\"}\n", '', self::BAD_LUA_ESCAPE];
    }
}
