<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\Codec\CsvDecoder;
use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CsvDecoderTest extends TestCase
{
    private const string PARSE_LINE_2 = 'csv: parse error on line 2';

    private const string RECORD_LINE_2 = 'csv: record on line 2';

    private const string XY_ROW = '[{"a":"x","b":"y"}]';

    private const string QUOTE_ERROR = ': extraneous or missing " in quoted-field';

    private const string FIELD_COUNT = ': wrong number of fields';

    private const string SIMPLE_ROW = '[{"a":1,"b":2}]';

    #[DataProvider('errorCases')]
    public function testErrorMessages(string $csv, string $expectedMessage, ?FormatOptions $options = null): void
    {
        try {
            $this->decode($csv, $options ?? new FormatOptions());
        } catch (FormatException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{string, string, 2?: FormatOptions}>
     */
    public static function errorCases(): iterable
    {
        yield 'text after a closing quote' => ["a,b\n\"x\"y,1\n", self::PARSE_LINE_2 . self::QUOTE_ERROR];

        yield 'text after a closing quote on the third line' => ["a,b\n1,2\n\"3\"x,4\n", 'csv: parse error on line 3' . self::QUOTE_ERROR];

        yield 'text after a closing quote after blank lines' => ["a,b\n1,2\n\n\n\"3\"x,4\n", 'csv: parse error on line 5' . self::QUOTE_ERROR];

        yield 'space after a closing quote' => ["a,b\n\"x\" ,y\n", self::PARSE_LINE_2 . self::QUOTE_ERROR];

        yield 'text after a closing quote before a multi-character separator' => [
            "a::b\n\"x\":y::z\n",
            self::PARSE_LINE_2 . self::QUOTE_ERROR,
            new FormatOptions(csvSeparator: '::'),
        ];

        yield 'text after a closing quote after a leading blank line' => ["\na,b\n1,2\n\"3\"x,4\n", 'csv: parse error on line 4' . self::QUOTE_ERROR];

        yield 'bare quote after a leading blank line' => ["\na,b\n1,x\"y\n", 'csv: parse error on line 3: bare " in non-quoted-field'];

        yield 'short record after a leading blank line' => ["\na,b\n1\n", 'csv: record on line 3' . self::FIELD_COUNT];
        yield 'bare quote' => ["a,b\n1,x\"y\n", 'csv: parse error on line 2: bare " in non-quoted-field'];

        yield 'bare quote on the third line' => ["a,b\n1,2\n3,x\"y\n", 'csv: parse error on line 3: bare " in non-quoted-field'];

        yield 'short record' => ["a,b\n1,2\n3\n", 'csv: record on line 3' . self::FIELD_COUNT];

        yield 'short record on the fourth line' => ["a,b\n1,2\n3,4\n5\n", 'csv: record on line 4' . self::FIELD_COUNT];

        yield 'long record after a blank line' => ["a,b\n1,2\n\n3,4,5\n", 'csv: record on line 4' . self::FIELD_COUNT];

        yield 'long record' => ["a,b\n1,2,3\n", self::RECORD_LINE_2 . self::FIELD_COUNT];

        yield 'quoted field alone in a record' => ["a,b\n\"x\"\r\n", self::RECORD_LINE_2 . self::FIELD_COUNT];

        yield 'quoted field at the end of the input' => ["a,b\n\"x\"", self::RECORD_LINE_2 . self::FIELD_COUNT];

        yield 'separator mismatch' => ["a,b\n1;2\n", self::RECORD_LINE_2 . self::FIELD_COUNT, new FormatOptions(csvSeparator: ';')];

        yield 'unterminated quote' => ["a,b\n\"x", 'csv: extraneous or missing " in quoted-field'];

        yield 'unterminated quote after a field' => ["a,b\n1,\"x", 'csv: extraneous or missing " in quoted-field'];

        yield 'flow collections without quotes split into columns' => ["a,b\n[1,2],{a: 1}\n", self::RECORD_LINE_2 . self::FIELD_COUNT];
    }

    #[DataProvider('shapeCases')]
    public function testShapes(string $csv, string $expectedJson, ?FormatOptions $options = null): void
    {
        $json = new JsonEncoder()->encode($this->decode($csv, $options ?? new FormatOptions()), new FormatOptions(indent: 0, unwrapScalar: false), 0);

        self::assertSame($expectedJson . "\n", $json);
    }

    /**
     * @return iterable<string, array{string, string, 2?: FormatOptions}>
     */
    public static function shapeCases(): iterable
    {
        yield 'carriage return and line feed' => ["a,b\r\n1,2\r\n", self::SIMPLE_ROW];

        yield 'lone carriage returns' => ["a,b\r1,2\r", self::SIMPLE_ROW];

        yield 'blank lines' => ["a,b\n\n\n1,2\n", self::SIMPLE_ROW];

        yield 'blank carriage return and line feed lines' => ["a,b\r\n\r\n1,2\r\n", self::SIMPLE_ROW];

        yield 'blank line with a bare carriage return and line feed' => ["a,b\n\r\n1,2", self::SIMPLE_ROW];

        yield 'leading blank lines' => ["\n\na,b\n1,2", self::SIMPLE_ROW];

        yield 'quoted fields' => ["a,b\n\"x\",\"y\"\n", self::XY_ROW];

        yield 'quoted field with a newline and a doubled quote' => ["a,b\n\"x\ny\",\"p\"\"q\"\n", '[{"a":"x y","b":"p\"q"}]'];

        yield 'quoted field with a carriage return and line feed' => ["a,b\n\"1\r\n2\",3\n", '[{"a":"1 2","b":3}]'];

        yield 'empty last field at the end of the input' => ["a,b\n1,", '[{"a":1,"b":null}]'];

        yield 'empty last field before a newline' => ["a,b\n1,\n", '[{"a":1,"b":null}]'];

        yield 'two empty fields' => ["a,b\n,\n", '[{"a":null,"b":null}]'];

        yield 'two empty quoted fields' => ["a,b\n\"\",\"\"\n", '[{"a":null,"b":null}]'];

        yield 'no trailing newline' => ["a,b\n1,2", self::SIMPLE_ROW];

        yield 'trailing newline' => ["a,b\n1,2\n", self::SIMPLE_ROW];

        yield 'one column' => ["a\n1\n", '[{"a":1}]'];

        yield 'header only' => ["a\n", '[]'];

        yield 'empty input' => ['', 'null'];

        yield 'only a newline' => ["\n", 'null'];

        yield 'multi-character separator with a lone separator character inside a field' => ["a::b\nx:y::z\n", '[{"a":"x:y","b":"z"}]', new FormatOptions(csvSeparator: '::')];

        yield 'multi-character separator' => ["a::b\nx::y\n", self::XY_ROW, new FormatOptions(csvSeparator: '::')];

        yield 'multi-character separator inside quotes' => ["a::b\n\"x::y\"::z\n", '[{"a":"x::y","b":"z"}]', new FormatOptions(csvSeparator: '::')];

        yield 'semicolon separator' => ["a;b\n1;2\n", self::SIMPLE_ROW, new FormatOptions(csvSeparator: ';')];

        yield 'empty separator falls back to a comma' => ["a,b\n1,2\n", self::SIMPLE_ROW, new FormatOptions(csvSeparator: '')];

        yield 'auto parse off keeps yaml text as a string' => ["a,b\n1,cool: true\n", '[{"a":1,"b":"cool: true"}]', new FormatOptions(csvAutoParse: false)];

        yield 'auto parse off keeps quoted flow collections as strings' => ["a,b\n\"[1, 2]\",\"{a: 1}\"\n", '[{"a":"[1, 2]","b":"{a: 1}"}]', new FormatOptions(csvAutoParse: false)];

        yield 'auto parse on parses yaml fields' => ["a,b\n1,cool: true\n", '[{"a":1,"b":{"cool":true}}]'];

        yield 'auto parse on parses quoted flow collections' => ["a,b\n\"[1, 2]\",\"{a: 1}\"\n", '[{"a":[1,2],"b":{"a":1}}]'];

        yield 'auto parse numbers' => ["a,b\n1.5,0x10\n", '[{"a":1.5,"b":16}]'];

        yield 'auto parse words' => ["a,b\ntrue,null\n", '[{"a":true,"b":null}]'];

        yield 'plain words with spaces and a leading dash' => ["a,b\nhello world,-x\n", '[{"a":"hello world","b":"-x"}]'];

        yield 'document markers inside a field' => ["a,b\n--- x,... y\n", self::XY_ROW];

        yield 'bare document markers' => ["a,b\n---,...\n", '[{"a":null,"b":"..."}]'];

        yield 'dash fields become sequences' => ["a,b\n-,- \n", '[{"a":[null],"b":[null]}]'];

        yield 'trailing space is trimmed by the yaml parser' => ["a,b\nx ,y \n", self::XY_ROW];

        yield 'alias and anchor lookalikes' => ["a,b\n*x,&x y\n", '[{"a":"*x","b":"y"}]'];

        yield 'multiple yaml documents stay a string' => ["a,b\n\"a: b: c\",\"x\n---\ny\"\n", '[{"a":"a: b: c","b":"x\n---\ny"}]'];

        yield 'byte order mark' => ["\u{FEFF}a,b\n1,2\n", self::SIMPLE_ROW];

        yield 'mixed line endings' => ["a,b\n1,2\r\n3,4\n", '[{"a":1,"b":2},{"a":3,"b":4}]'];

        yield 'doubled carriage returns' => ["a,b\n1,2\r\r3,4", '[{"a":1,"b":2},{"a":3,"b":4}]'];

        yield 'carriage return and line feed header' => ["a,b\r\n1,2", self::SIMPLE_ROW];

        yield 'quoted last field before carriage return and line feed' => ["a,b\r\n1,\"x\"\r\n", '[{"a":1,"b":"x"}]'];

        yield 'quoted field then an empty field' => ["a,b\n\"x\",\r\n", '[{"a":"x","b":null}]'];

        yield 'quoted last field at the end of the input' => ["a,b\n1,\"x\"", '[{"a":1,"b":"x"}]'];
    }

    public function testFormatDefaultsToCsv(): void
    {
        self::assertSame(FormatEnum::Csv, new CsvDecoder()->format());
        self::assertSame(FormatEnum::Tsv, new CsvDecoder(FormatEnum::Tsv)->format());
    }

    public function testTabSeparatedInputUsesTheTabSeparator(): void
    {
        $document = $this->decodeWith(new CsvDecoder(FormatEnum::Tsv), "a\tb\n1\t2\n", new FormatOptions());
        $json     = new JsonEncoder()->encode($document, new FormatOptions(indent: 0, unwrapScalar: false), 0);

        self::assertSame(self::SIMPLE_ROW . "\n", $json);
    }

    public function testTabSeparatedInputUsesTheConfiguredTabSeparator(): void
    {
        $document = $this->decodeWith(new CsvDecoder(FormatEnum::Tsv), "a|b\n1|2\n", new FormatOptions(tsvSeparator: '|'));
        $json     = new JsonEncoder()->encode($document, new FormatOptions(indent: 0, unwrapScalar: false), 0);

        self::assertSame(self::SIMPLE_ROW . "\n", $json);
    }

    private function decode(string $csv, FormatOptions $options): Node
    {
        return $this->decodeWith(new CsvDecoder(), $csv, $options);
    }

    private function decodeWith(CsvDecoder $decoder, string $csv, FormatOptions $options): Node
    {
        foreach ($decoder->decode($csv, $options) as $document) {
            return $document;
        }

        self::fail('no document');
    }
}
