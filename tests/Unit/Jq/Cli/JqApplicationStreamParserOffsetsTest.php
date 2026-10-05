<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\ParseDiagnostics;
use LTS\PhpXq\Jq\Cli\StreamError;
use LTS\PhpXq\Jq\Cli\StreamParser;
use LTS\PhpXq\Json\EncodeOptions;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The offset each streaming event is reported at (the position just after it, which becomes its line number),
 * and where and how each structural error is reported, with and without `--seq`.
 *
 * @internal
 */
final class JqApplicationStreamParserOffsetsTest extends TestCase
{
    private const string RECORD_SEPARATOR = "\x1e";

    private const string POSITION = ' at line 1, column ';

    private const string FIRST_OF_ARRAY = '[[0],1]';

    private const string CLOSE_ARRAY = '[[1]]';

    private const string UNDER_KEY = '["a"]';

    private const string UNDER_NULL = '[null]';

    private const string UNDER_ZERO = '[0]';

    private StreamParser $parser;

    /**
     * @param list<array{int, string}> $expected offset and compact JSON of every event
     */
    #[DataProvider('streams')]
    public function testEventsAreReportedJustAfterTheirText(string $text, array $expected): void
    {
        self::assertSame($expected, $this->events($text, false));

        $shifted = [];
        foreach ($expected as [$offset, $json]) {
            $shifted[] = [$offset + 1, $json];
        }

        self::assertSame($shifted, $this->events(self::RECORD_SEPARATOR . $text, true));
    }

    /**
     * @return iterable<string, array{string, list<array{int, string}>}>
     */
    public static function streams(): iterable
    {
        yield 'array of numbers' => ['[1,2]', [[2, self::FIRST_OF_ARRAY], [4, '[[1],2]'], [5, self::CLOSE_ARRAY]]];
        yield 'empty array' => ['[]', [[2, '[[],[]]']]];
        yield 'empty object' => ['{}', [[2, '[[],{}]']]];
        yield 'empty containers inside an array' => ['[[],{}]', [[3, '[[0],[]]'], [6, '[[1],{}]'], [7, self::CLOSE_ARRAY]]];
        yield 'nested containers' => [
            '{"a":[1,{"b":2}]}',
            [[7, '[["a",0],1]'], [14, '[["a",1,"b"],2]'], [15, '[["a",1,"b"]]'], [16, '[["a",1]]'], [17, '[["a"]]']],
        ];
        yield 'two top level numbers' => ['1 2', [[1, '[[],1]'], [3, '[[],2]']]];
        yield 'an empty string' => ['""', [[2, '[[],""]']]];
        yield 'two top level strings' => ['"a" "b"', [[3, '[[],"a"]'], [7, '[[],"b"]']]];
        yield 'blank lines inside an array' => ["[1,\n\n2]\n3", [[2, self::FIRST_OF_ARRAY], [6, '[[1],2]'], [7, self::CLOSE_ARRAY], [9, '[[],3]']]];
    }

    /**
     * @param list<array{string, int, string}> $cases
     */
    #[DataProvider('errors')]
    public function testStructuralErrorsAreReportedJustAfterTheOffendingCharacter(string $message, array $cases): void
    {
        foreach ($cases as [$text, $offset, $path]) {
            $last = $this->lastError($text, false);
            self::assertSame($offset, $last[0], $text);
            self::assertSame($message . self::POSITION . $offset, $last[1]->message, $text);
            self::assertSame($path, json_encode($last[1]->path), $text);
            self::assertTrue($last[1]->fatal, $text);

            $recovered = $this->lastError(self::RECORD_SEPARATOR . $text, true);
            self::assertSame($offset + 1, $recovered[0], $text);
            self::assertSame($message . self::POSITION . ($offset + 1), $recovered[1]->message, $text);
            self::assertSame($path, json_encode($recovered[1]->path), $text);
            self::assertFalse($recovered[1]->fatal, $text);
        }
    }

    /**
     * @return iterable<string, array{string, list<array{string, int, string}>}>
     */
    public static function errors(): iterable
    {
        $table = [
            "',' not as part of an object or array"    => [[',', 1, '[]']],
            "':' not as part of an object"             => [[':', 1, '[]']],
            "Expected string key before ':'"           => [['{:', 2, self::UNDER_NULL], ['{"a":1,:', 8, self::UNDER_NULL]],
            "':' should follow a key"                  => [['{"a"::', 6, self::UNDER_KEY]],
            'Objects must consist of key:value pairs'  => [['{"a",', 5, self::UNDER_KEY], ['{"a"}', 5, self::UNDER_KEY], ['{"a":}', 6, self::UNDER_KEY]],
            "Expected value before ','"                => [['{"a":1,,}', 8, self::UNDER_NULL], ['{,', 2, self::UNDER_NULL], ['[,', 2, self::UNDER_ZERO]],
            'Expected separator between values'        => [['[1 2]', 5, self::UNDER_ZERO]],
            'Object keys must be strings'              => [['{1:2}', 3, self::UNDER_NULL]],
            "Unmatched ']' at the top-level"           => [[']', 1, '[]']],
            "Unmatched '}' at the top-level"           => [['}', 1, '[]']],
            "Unmatched '}' in the middle of an array"  => [['[}', 2, self::UNDER_ZERO]],
            "Unmatched ']' in the middle of an object" => [['{]', 2, self::UNDER_NULL]],
            'Expected another array element'           => [['[1,]', 4, '[1]']],
            'Expected another key-value pair'          => [['{"a":1,}', 8, self::UNDER_NULL]],
        ];

        foreach ($table as $message => $cases) {
            yield $message => [$message, $cases];
        }
    }

    public function testAnUnfinishedStringIsReportedAtTheEndOfTheText(): void
    {
        $last = $this->lastError('"abc', false);

        self::assertSame(4, $last[0]);
        self::assertSame('Unfinished string at EOF' . self::POSITION . '4', $last[1]->message);
        self::assertTrue($last[1]->fatal);

        $recovered = $this->lastError(self::RECORD_SEPARATOR . '"abc' . self::RECORD_SEPARATOR . '1', true);
        self::assertSame(7, $recovered[0]);
        self::assertSame('Unfinished string at EOF' . self::POSITION . '7', $recovered[1]->message);
        self::assertFalse($recovered[1]->fatal);
    }

    public function testAnUnfinishedContainerIsReportedAtTheEndOfTheText(): void
    {
        $last = $this->lastError("[1,\n2", false);

        self::assertSame(5, $last[0]);
        self::assertSame('Unfinished JSON term at EOF at line 2, column 1', $last[1]->message);
        self::assertTrue($last[1]->fatal);

        $recovered = $this->lastError(self::RECORD_SEPARATOR . "[1,\n2", true);
        self::assertSame(6, $recovered[0]);
        self::assertSame('Unfinished abandoned text at EOF at line 2, column 1', $recovered[1]->message);
        self::assertFalse($recovered[1]->fatal);
    }

    public function testARecordSeparatorInsideAContainerTruncatesIt(): void
    {
        self::assertSame(
            [[3, self::FIRST_OF_ARRAY], [5, 'ERR Truncated value at line 1, column 5 [1] n'], [6, '[[],2]']],
            $this->events(self::RECORD_SEPARATOR . '[1,' . self::RECORD_SEPARATOR . '2', true),
        );
        self::assertSame(
            [[8, 'ERR Truncated value at line 1, column 8 ["a",0] n'], [10, '[[],3]']],
            $this->events(self::RECORD_SEPARATOR . '{"a":[' . self::RECORD_SEPARATOR . self::RECORD_SEPARATOR . '3', true),
        );
    }

    public function testADamagedScalarEndsItsRecordWithoutEndingTheStream(): void
    {
        self::assertSame(
            [[4, 'ERR Invalid JSON text [] n'], [6, '[[],1]']],
            $this->events(self::RECORD_SEPARATOR . 'tru' . self::RECORD_SEPARATOR . '1', true),
        );
        self::assertSame(
            [[5, 'ERR Invalid JSON text [0] n'], [8, '[[],5]']],
            $this->events(self::RECORD_SEPARATOR . '[tru]' . self::RECORD_SEPARATOR . '5', true),
        );
    }

    public function testAStructuralErrorEndsItsRecordWithoutEndingTheStream(): void
    {
        self::assertSame(
            [[3, self::FIRST_OF_ARRAY], [5, 'ERR Expected another array element at line 1, column 5 [1] n'], [7, '[[],7]'], [9, '[[],8]']],
            $this->events(self::RECORD_SEPARATOR . '[1,]' . self::RECORD_SEPARATOR . '7 8', true),
        );
    }

    public function testADamagedScalarEndsTheStreamWithoutSeq(): void
    {
        self::assertSame([[3, 'ERR Invalid literal at EOF at line 1, column 3 [] f']], $this->events('tru', false));
        self::assertSame([[4, 'ERR Invalid literal at line 1, column 5 [0] f']], $this->events('[tru]', false));
    }

    /**
     * @return list<array{int, string}> offset and either compact JSON or "ERR message path n|f"
     */
    private function events(string $text, bool $seq): array
    {
        $encoder = new JsonEncoder();
        $events  = [];
        foreach ($this->parser->events($text, $seq) as $offset => $event) {
            $events[] = [$offset, $event instanceof StreamError
                ? 'ERR ' . $event->message . ' ' . json_encode($event->path) . ' ' . ($event->fatal ? 'f' : 'n')
                : $encoder->encode($event, EncodeOptions::compact())];
        }

        return $events;
    }

    /**
     * @return array{int, StreamError}
     */
    private function lastError(string $text, bool $seq): array
    {
        $last = null;
        foreach ($this->parser->events($text, $seq) as $offset => $event) {
            if ($event instanceof StreamError) {
                $last = [$offset, $event];
            }
        }

        self::assertNotNull($last, $text);

        return $last;
    }

    protected function setUp(): void
    {
        $decoder      = new JsonDecoder();
        $this->parser = new StreamParser($decoder, new ParseDiagnostics($decoder));
    }
}
