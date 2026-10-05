<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\CsvDecoder;
use LTS\PhpXq\Yq\Format\Codec\CsvEncoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CsvCodecTest extends TestCase
{
    public function testDecodesRowsIntoObjectsWithAutoParsing(): void
    {
        $csv = "name,numberOfCats,likesApples,height,facts\nGary,1,true,168.8,cool: true\nSamantha's Rabbit,2,false,-188.8,tall: indeed\n\n";

        $expected = <<<'YAML'
            - name: Gary
              numberOfCats: 1
              likesApples: true
              height: 168.8
              facts:
                cool: true
            - name: Samantha's Rabbit
              numberOfCats: 2
              likesApples: false
              height: -188.8
              facts:
                tall: indeed

            YAML;

        self::assertSame($expected, $this->decodeToYaml($csv, FormatEnum::Csv));
    }

    public function testDecodesWithoutAutoParsing(): void
    {
        $out = $this->decodeToYaml("a,b\n1,cool: true\n", FormatEnum::Csv, new FormatOptions(csvAutoParse: false));

        self::assertSame("- a: 1\n  b: 'cool: true'\n", $out);
    }

    public function testDecodesTsv(): void
    {
        $out = $this->decodeToYaml("name\tn\nGary\t1\nSam\t2\n", FormatEnum::Tsv);

        self::assertSame("- name: Gary\n  n: 1\n- name: Sam\n  n: 2\n", $out);
    }

    public function testQuotedFields(): void
    {
        $csv  = "a,b\n\"x,y\",\"say \"\"hi\"\"\"\n\"multi\nline\",plain\n";
        $rows = $this->decodeDoc($csv, FormatEnum::Csv, new FormatOptions(csvAutoParse: false))->root()->content;

        self::assertSame('x,y', $rows[0]->content[1]->value);
        self::assertSame('say "hi"', $rows[0]->content[3]->value);
        self::assertSame("multi\nline", $rows[1]->content[1]->value);
    }

    public function testCustomSeparatorAndCrlf(): void
    {
        $rows = $this->decodeDoc("a;b\r\n1;2\r\n", FormatEnum::Csv, new FormatOptions(csvSeparator: ';'))->root()->content;

        self::assertSame('1', $rows[0]->content[1]->value);
        self::assertSame('2', $rows[0]->content[3]->value);
    }

    public function testEmptyFieldsAreNull(): void
    {
        $row = $this->decodeDoc("a,b,c\n1,,\n", FormatEnum::Csv)->root()->content[0];

        self::assertSame('1', $row->content[1]->value);
        self::assertSame('!!null', $row->content[3]->tag);
        self::assertSame('!!null', $row->content[5]->tag);
    }

    public function testWrongFieldCountIsAnError(): void
    {
        $this->expectException(FormatException::class);
        $this->decodeDoc("a,b\n1\n", FormatEnum::Csv);
    }

    public function testBareQuoteIsAnError(): void
    {
        $this->expectException(FormatException::class);
        $this->decodeDoc("a\nx\"y\n", FormatEnum::Csv);
    }

    public function testStrayTextAfterClosingQuoteIsAnError(): void
    {
        $this->expectException(FormatException::class);
        $this->decodeDoc("a\n\"un\"closed\n", FormatEnum::Csv);
    }

    public function testHeaderOnlyIsAnEmptyArray(): void
    {
        self::assertSame([], $this->decodeDoc("a,b\n", FormatEnum::Csv)->root()->content);
    }

    public function testEmptyInputIsOneNullDocument(): void
    {
        $docs = [...new CsvDecoder(FormatEnum::Csv)->decode('', new FormatOptions())];

        self::assertCount(1, $docs);
        self::assertSame('!!null', $docs[0]->root()->tag);
    }

    public function testBomIsSkipped(): void
    {
        $row = $this->decodeDoc("\u{FEFF}a\n1\n", FormatEnum::Csv)->root()->content[0];

        self::assertSame('a', $row->content[0]->value);
    }

    public function testUnterminatedQuoteIsAnError(): void
    {
        $this->expectException(FormatException::class);
        $this->decodeDoc("a\n\"oops\n", FormatEnum::Csv);
    }

    #[DataProvider('encodeCases')]
    public function testEncode(string $yaml, string $expected, FormatEnum $format = FormatEnum::Csv, int $index = 0): void
    {
        self::assertSame($expected, $this->encode($yaml, $format, new FormatOptions(), $index));
    }

    /**
     * @return iterable<string, array{string, string, 2?: FormatEnum, 3?: int}>
     */
    public static function encodeCases(): iterable
    {
        yield 'rows' => ["- [i, like, csv]\n- [because, excel, is, cool]\n", "i,like,csv\nbecause,excel,is,cool\n"];

        yield 'tsv rows' => ["- [i, like, csv]\n- [because, excel, is, cool]\n", "i\tlike\tcsv\nbecause\texcel\tis\tcool\n", FormatEnum::Tsv];

        yield 'objects get a header' => ["- name: Gary\n  n: 1\n  likes: true\n  h: 168.8\n- name: Samantha's Rabbit\n  n: 2\n  likes: false\n  h: -188.8\n", "name,n,likes,h\nGary,1,true,168.8\nSamantha's Rabbit,2,false,-188.8\n"];

        yield 'missing and extra fields' => ["- name: Gary\n  n: 1\n  h: 168.8\n- name: Sam\n  h: -188.8\n  likes: false\n", "name,n,h\nGary,1,168.8\nSam,,-188.8\n"];

        yield 'later results have no header' => ["- a: 1\n- a: 2\n", "1\n2\n", FormatEnum::Csv, 1];

        yield 'single row of scalars' => ["[cat, 'thing1,thing2', true, 3.40]\n", "cat,\"thing1,thing2\",true,3.40\n"];

        yield 'quotes and newlines' => ["- ['say \"hi\"', \"a\\nb\", ' lead', '']\n", "\"say \"\"hi\"\"\",\"a\nb\",\" lead\",\n"];

        yield 'tsv quotes tabs' => ["- ['a\tb', c]\n", "\"a\tb\"\tc\n", FormatEnum::Tsv];

        yield 'scalar root' => ["hello\n", "hello\n"];

        yield 'empty array' => ["[]\n", ''];
    }

    public function testCustomSeparatorOnEncode(): void
    {
        self::assertSame("a;b\n", $this->encode("- [a, b]\n", FormatEnum::Csv, new FormatOptions(csvSeparator: ';')));
        self::assertSame("a|b\n", $this->encode("- [a, b]\n", FormatEnum::Tsv, new FormatOptions(tsvSeparator: '|')));
    }

    public function testNestedCellsAreRejected(): void
    {
        $this->expectException(FormatException::class);
        $this->encode("- [[1], 2]\n", FormatEnum::Csv, new FormatOptions());
    }

    public function testMappingRootIsRejected(): void
    {
        $this->expectException(FormatException::class);
        $this->encode("a: 1\n", FormatEnum::Csv, new FormatOptions());
    }

    public function testFormats(): void
    {
        self::assertSame(FormatEnum::Tsv, new CsvDecoder(FormatEnum::Tsv)->format());
        self::assertSame(FormatEnum::Csv, new CsvEncoder(FormatEnum::Csv)->format());
    }

    private function decodeDoc(string $text, FormatEnum $format, ?FormatOptions $options = null): Node
    {
        foreach (new CsvDecoder($format)->decode($text, $options ?? new FormatOptions()) as $document) {
            return $document;
        }

        self::fail('no document');
    }

    private function decodeToYaml(string $text, FormatEnum $format, ?FormatOptions $options = null): string
    {
        return new YamlEmitter()->emit($this->decodeDoc($text, $format, $options));
    }

    private function encode(string $yaml, FormatEnum $format, FormatOptions $options, int $index = 0): string
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            return new CsvEncoder($format)->encode($document, $options, $index);
        }

        self::fail('no document');
    }
}
