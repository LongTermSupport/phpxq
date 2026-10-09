<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use LTS\PhpXq\Tests\Support\CliRunner;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\Codec\LuaReader;
use LTS\PhpXq\Yq\Format\Codec\XmlReader;
use LTS\PhpXq\Yq\Runtime\Args;
use LTS\PhpXq\Yq\Runtime\EvaluationException;
use LTS\PhpXq\Yq\Runtime\Evaluator;
use LTS\PhpXq\Yq\Runtime\Numbers;
use LTS\PhpXq\Yq\Runtime\Operators\DateCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * PHP 8.5 refuses to cast a float outside the int range, and the CLI turned that into an internal error. Every
 * number that reaches an int is checked first. Where yq requires an int (slice bounds, function arguments) a
 * number that is not one is Go's `strconv.ParseInt` error; a unix time may have a fraction but must fit the
 * int range; a character reference or escape beyond Unicode is kept verbatim or rejected as the format does.
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

    private const string INVALID_SYNTAX = 'invalid syntax';

    private const string FRACTION = '1.5';

    private const string HUGE = '1e30';

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

    /**
     * @return iterable<string, array{list<string>, string, string, string}>
     */
    public static function handled(): iterable
    {
        yield 'slice end past the int range'    => [[...self::NULL_TO_JSON, '[1,2] | .[0:1e30]'], '', '', self::parseIntError(self::HUGE)];
        yield 'slice start past the int range'  => [[...self::NULL_TO_JSON, '[1,2] | .[1e30:]'], '', '', self::parseIntError(self::HUGE)];
        yield 'slice end below the int range'   => [[...self::NULL_TO_JSON, '[1,2] | .[0:-1e30]'], '', '', self::parseIntError('-1e30')];
        yield 'slice end with a fraction'       => [[...self::NULL_TO_JSON, '[1,2] | .[0:1.5]'], '', '', self::parseIntError(self::FRACTION)];
        yield 'string slice with a fraction'    => [['-n', '"abc" | .[0:1.5]'], '', '', self::parseIntError(self::FRACTION)];
        yield 'integer argument past the range' => [['-n', '[[1,[2]]] | flatten(1e30)'], '', '', self::parseIntError(self::HUGE)];
        yield 'an int slice bound still works'  => [[...self::NULL_TO_JSON, '[1,2,3] | .[0:2]'], '', "[1,2]\n", ''];
        yield 'unix time past the int range'    => [['-n', '1e30 | from_unix'], '', '', "Error: cannot convert 1e30 to a unix time\n"];
        yield 'unix time with a fraction'       => [['-n', '1.5 | from_unix'], '', "1970-01-01T00:00:01Z\n", ''];
        yield 'xml reference past the range'    => [self::XML_TO_JSON, "<a>&#x99999999999999999999;</a>\n", "{\"a\":\"&#x99999999999999999999;\"}\n", ''];
        yield 'xml reference past unicode'      => [self::XML_TO_JSON, "<a>&#x110000;</a>\n", "{\"a\":\"&#x110000;\"}\n", ''];
        yield 'lua escape past unicode'         => [self::FROM_LUA, "return {a=\"\\u{110000}\"}\n", '', self::BAD_LUA_ESCAPE];
        yield 'lua escape past the int range'   => [self::FROM_LUA, "return {a=\"\\u{99999999999999999999}\"}\n", '', self::BAD_LUA_ESCAPE];
        yield 'lua escape of a surrogate'       => [self::FROM_LUA, "return {a=\"\\u{D800}\"}\n", '', self::BAD_LUA_ESCAPE];
    }

    #[DataProvider('integers')]
    public function testIntOfAcceptsOnlyIntegers(string $text, string $tag, ?int $expected): void
    {
        self::assertSame($expected, Numbers::intOf(Node::scalar($text, $tag)));
    }

    /**
     * @return iterable<string, array{string, string, ?int}>
     */
    public static function integers(): iterable
    {
        yield 'an int'                     => ['7', CoreSchema::TAG_INT, 7];
        yield 'a negative int'             => ['-7', CoreSchema::TAG_INT, -7];
        yield 'a hex int'                  => ['0x10', CoreSchema::TAG_INT, 16];
        yield 'a float written as an int'  => ['2', CoreSchema::TAG_FLOAT, 2];
        yield 'a string is not a number'   => ['7', CoreSchema::TAG_STR, null];
    }

    #[DataProvider('nonIntegers')]
    public function testIntOfRejectsOtherNumbersAsGoParseIntDoes(string $text, string $tag, string $reason): void
    {
        try {
            Numbers::intOf(Node::scalar($text, $tag));
            self::fail('expected an evaluation error');
        } catch (EvaluationException $evaluationException) {
            self::assertSame(\sprintf('strconv.ParseInt: parsing "%s": %s', $text, $reason), $evaluationException->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function nonIntegers(): iterable
    {
        yield 'a fraction'                => [self::FRACTION, CoreSchema::TAG_FLOAT, self::INVALID_SYNTAX];
        yield 'a whole float'             => ['2.0', CoreSchema::TAG_FLOAT, self::INVALID_SYNTAX];
        yield 'an exponent'               => [self::HUGE, CoreSchema::TAG_FLOAT, self::INVALID_SYNTAX];
        yield 'infinity'                  => ['.inf', CoreSchema::TAG_FLOAT, self::INVALID_SYNTAX];
        yield 'not a number'              => ['.nan', CoreSchema::TAG_FLOAT, self::INVALID_SYNTAX];
        yield 'an int past the range'     => ['99999999999999999999', CoreSchema::TAG_INT, 'value out of range'];
        yield 'an int below the range'    => ['-99999999999999999999', CoreSchema::TAG_INT, 'value out of range'];
    }

    private static function parseIntError(string $text): string
    {
        return \sprintf("Error: strconv.ParseInt: parsing \"%s\": %s\n", $text, self::INVALID_SYNTAX);
    }
}
