<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Jq\Cli;

use LTS\PhpXq\Jq\Cli\ParseDiagnostics;
use LTS\PhpXq\Jq\Cli\StreamError;
use LTS\PhpXq\Jq\Cli\StreamParser;
use LTS\PhpXq\Json\JsonDecoder;
use LTS\PhpXq\Json\JsonObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JqApplicationStreamParserTest extends TestCase
{
    #[DataProvider('provideEvents')]
    public function testEvents(string $text, string $expected): void
    {
        $events = [];
        foreach ($this->parser()->events($text, false) as $event) {
            self::assertIsArray($event);
            $events[] = json_encode($event, \JSON_THROW_ON_ERROR);
        }

        self::assertSame($expected, implode(' ', $events));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEvents(): iterable
    {
        yield 'scalar' => ['3', '[[],3]'];

        yield 'string' => ['"a"', '[[],"a"]'];

        yield 'empty array' => ['[]', '[[],[]]'];

        yield 'empty object' => ['{}', '[[],{}]'];

        yield 'flat array' => ['[1,2]', '[[0],1] [[1],2] [[1]]'];

        yield 'flat object' => ['{"a":1,"b":2}', '[["a"],1] [["b"],2] [["b"]]'];

        yield 'nested' => ['[1,[2]]', '[[0],1] [[1,0],2] [[1,0]] [[1]]'];

        yield 'object in array' => ['[{"a":[true]}]', '[[0,"a",0],true] [[0,"a",0]] [[0,"a"]] [[0]]'];

        yield 'empty containers inside' => ['{"a":[],"b":{}}', '[["a"],[]] [["b"],{}] [["b"]]'];

        yield 'several top-level values' => ['1 [2]{"a":3}', '[[],1] [[0],2] [[0]] [["a"],3] [["a"]]'];

        yield 'whitespace everywhere' => [" [ 1 ,\n 2 ] ", '[[0],1] [[1],2] [[1]]'];

        yield 'null and false' => ['[null,false]', '[[0],null] [[1],false] [[1]]'];

        yield 'array after array' => ['[1][2]', '[[0],1] [[0]] [[0],2] [[0]]'];
    }

    public function testKeysAreTheOffsetsJustAfterEachItem(): void
    {
        $offsets = [];
        foreach ($this->parser()->events("[1,\n2]\n", false) as $offset => $event) {
            $offsets[] = $offset;
        }

        self::assertSame([2, 5, 6], $offsets);
    }

    public function testEmptyObjectLeafIsAJsonObject(): void
    {
        foreach ($this->parser()->events('{}', false) as $event) {
            self::assertIsArray($event);
            self::assertInstanceOf(JsonObject::class, $event[1]);
        }
    }

    #[DataProvider('provideErrors')]
    public function testErrorsCarryJqsWordingAndThePath(string $text, string $message, string $path): void
    {
        $error = null;
        foreach ($this->parser()->events($text, false) as $event) {
            if ($event instanceof StreamError) {
                $error = $event;
            }
        }

        self::assertNotNull($error);
        self::assertSame($message, $error->message);
        self::assertSame($path, json_encode($error->path, \JSON_THROW_ON_ERROR));
        self::assertTrue($error->fatal);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideErrors(): iterable
    {
        yield 'unfinished array' => ['[', 'Unfinished JSON term at EOF at line 1, column 1', '[0]'];

        yield 'unfinished after a number' => ['[1,2', 'Unfinished JSON term at EOF at line 1, column 4', '[1]'];

        yield 'unfinished object' => ['{"a":', 'Unfinished JSON term at EOF at line 1, column 5', '["a"]'];

        yield 'trailing newline column' => ["[1,\n", 'Unfinished JSON term at EOF at line 2, column 0', '[1]'];

        yield 'key without value' => ['{"a":1,"b",', 'Objects must consist of key:value pairs at line 1, column 11', '["b"]'];

        yield 'object as key' => ['{{"a":"b"}}', "Expected string key after '{', not '{' at line 1, column 2", '[null]'];

        yield 'object as key after comma' => ['{"x":"y",{"a":"b"}}', "Expected string key after ',' in object, not '{' at line 1, column 10", '[null]'];

        yield 'array as key' => ['{["a","b"]}', "Expected string key after '{', not '[' at line 1, column 2", '[null]'];

        yield 'array as key after comma' => ['{"x":"y",["a","b"]}', "Expected string key after ',' in object, not '[' at line 1, column 10", '[null]'];

        yield 'number as key' => ['{1:2}', 'Object keys must be strings at line 1, column 3', '[null]'];

        yield 'unmatched bracket' => [']', "Unmatched ']' at the top-level at line 1, column 1", '[]'];

        yield 'unmatched brace' => ['}', "Unmatched '}' at the top-level at line 1, column 1", '[]'];

        yield 'brace in array' => ['[}', "Unmatched '}' in the middle of an array at line 1, column 2", '[0]'];

        yield 'bracket in object' => ['{]', "Unmatched ']' in the middle of an object at line 1, column 2", '[null]'];

        yield 'trailing comma in array' => ['[1,]', 'Expected another array element at line 1, column 4', '[1]'];

        yield 'trailing comma in object' => ['{"a":1,}', 'Expected another key-value pair at line 1, column 8', '[null]'];

        yield 'comma at top level' => [',', "',' not as part of an object or array at line 1, column 1", '[]'];

        yield 'empty element' => ['[,1]', "Expected value before ',' at line 1, column 2", '[0]'];

        yield 'colon at top level' => [':', "':' not as part of an object at line 1, column 1", '[]'];

        yield 'colon in array' => ['[:', "':' not as part of an object at line 1, column 2", '[0]'];

        yield 'colon without key' => ['{:', "Expected string key before ':' at line 1, column 2", '[null]'];

        yield 'two values without a comma' => ['[1 2]', 'Expected separator between values at line 1, column 5', '[0]'];

        yield 'unfinished string' => ['["abc', 'Unfinished string at EOF at line 1, column 5', '[0]'];

        yield 'value where a colon is due' => ['{"a" 1}', 'Expected separator between values at line 1, column 7', '["a"]'];
    }

    public function testParsingStopsAfterAnError(): void
    {
        $events = iterator_to_array($this->parser()->events('[1,]  [2]', false), false);

        self::assertCount(2, $events);
        self::assertInstanceOf(StreamError::class, $events[1]);
    }

    public function testEventsBeforeAnErrorAreStillProduced(): void
    {
        $events = iterator_to_array($this->parser()->events('[1,2', false), false);

        self::assertSame([[[0], 1], [[1], 2]], \array_slice($events, 0, 2));
        self::assertInstanceOf(StreamError::class, $events[2]);
    }

    public function testInvalidTokenUsesTheDecodersMessageWithWholeTextPositions(): void
    {
        $error = null;
        foreach ($this->parser()->events("[1,\n foo]", false) as $event) {
            if ($event instanceof StreamError) {
                $error = $event;
            }
        }

        self::assertNotNull($error);
        self::assertStringStartsWith('Invalid literal at line 2', $error->message);
    }

    public function testSeqSeparatorAbandonsTheValueInProgress(): void
    {
        $events = iterator_to_array($this->parser()->events("[1,\x1e2 \x1e[3]", true), false);

        self::assertSame([[0], 1], $events[0]);
        self::assertInstanceOf(StreamError::class, $events[1]);
        self::assertSame('Truncated value at line 1, column 4', $events[1]->message);
        self::assertFalse($events[1]->fatal);
        self::assertSame([[], 2], $events[2]);
        self::assertSame([[0], 3], $events[3]);
        self::assertSame([[0]], $events[4]);
    }

    public function testSeqModeResumesAfterAnErrorAtTheNextSeparator(): void
    {
        $events = iterator_to_array($this->parser()->events("[1 2]\x1e[3]", true), false);

        self::assertSame([[0], 1], $events[0]);
        self::assertInstanceOf(StreamError::class, $events[1]);
        self::assertFalse($events[1]->fatal);
        self::assertSame([[0], 3], $events[2]);
    }

    public function testSeqModeReportsAnUnfinishedTailAsAbandonedText(): void
    {
        $events = iterator_to_array($this->parser()->events('[1', true), false);

        self::assertInstanceOf(StreamError::class, $events[1]);
        self::assertSame('Unfinished abandoned text at EOF at line 1, column 2', $events[1]->message);
        self::assertFalse($events[1]->fatal);
    }

    public function testSeparatorWithoutSeqIsPartOfTheToken(): void
    {
        $events = iterator_to_array($this->parser()->events("1\x1e", false), false);

        self::assertInstanceOf(StreamError::class, $events[0]);
    }

    private function parser(): StreamParser
    {
        $decoder = new JsonDecoder();

        return new StreamParser($decoder, new ParseDiagnostics($decoder));
    }
}
