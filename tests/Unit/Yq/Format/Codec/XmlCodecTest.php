<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\Codec\XmlDecoder;
use LTS\PhpXq\Yq\Format\Codec\XmlEncoder;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class XmlCodecTest extends TestCase
{
    #[DataProvider('decodeCases')]
    public function testDecodeShape(string $xml, string $expectedJson, ?FormatOptions $options = null): void
    {
        $options ??= new FormatOptions();
        $json = new JsonEncoder()->encode($this->decode($xml, $options), new FormatOptions(indent: 0, unwrapScalar: false), 0);

        self::assertSame($expectedJson . "\n", $json);
    }

    /**
     * @return iterable<string, array{string, string, 2?: FormatOptions}>
     */
    public static function decodeCases(): iterable
    {
        yield 'repeated children become an array' => ['<a><b>t</b><b>u</b></a>', '{"a":{"b":["t","u"]}}'];

        yield 'attributes come first, then content, then children' => [
            '<a x="1" y=\'q&amp;r\'>text<b>1</b></a>',
            '{"a":{"+content":"text","+@x":"1","+@y":"q&r","b":"1"}}',
        ];

        yield 'text pieces around children' => ['<a><b>1</b>text<b>2</b></a>', '{"a":{"+content":"text","b":["1","2"]}}'];

        yield 'content split by a comment' => ['<root>  value  <!--c-->anotherValue <a>frog</a> cool!</root>', '{"root":{"+content":["value","anotherValue","cool!"],"a":"frog"}}'];

        yield 'empty elements are null' => ['<a><b/><c></c></a>', '{"a":{"b":null,"c":null}}'];

        yield 'cdata and character references' => ['<a><![CDATA[ raw <x> ]]>&#65;&#x42;&lt;&amp;&gt;&apos;&quot;</a>', '{"a":["raw <x>","AB<&>\'\""]}'];

        yield 'unknown entities stay as written' => ['<a>&unknown; &</a>', '{"a":"&unknown; &"}'];

        yield 'processing instruction and directive' => [
            "<?xml version=\"1.0\"?>\n<!DOCTYPE a>\n<a><?pi data?></a>",
            '{"+p_xml":"version=\"1.0\"","+directive":"DOCTYPE a","a":{"+p_pi":"data"}}',
        ];

        yield 'skip processing instructions and directives' => [
            "<?xml version=\"1.0\"?>\n<!DOCTYPE a>\n<a/>",
            '{"a":null}',
            new FormatOptions(xmlSkipProcInst: true, xmlSkipDirectives: true),
        ];

        yield 'nested directive with quotes and comment' => ['<!DOCTYPE a [<!ENTITY e "x>y"> <!-- gone -->]><a/>', '{"+directive":"DOCTYPE a [<!ENTITY e \"x>y\">  ]","a":null}'];

        yield 'raw namespace names' => ['<x:a xmlns:x="urn:x" x:k="v"/>', '{"x:a":{"+@xmlns:x":"urn:x","+@x:k":"v"}}'];

        yield 'resolved namespaces' => [
            '<root xmlns="urn:x" xmlns:p="urn:p"><p:a p:k="v">1</p:a><b q="1"/></root>',
            '{"urn:x:root":{"+@xmlns":"urn:x","+@xmlns:p":"urn:p","urn:p:a":{"+content":"1","+@urn:p:k":"v"},"urn:x:b":{"+@q":"1"}}}',
            new FormatOptions(xmlRawToken: false),
        ];

        yield 'drop namespaces' => ['<x:a xmlns:x="urn:x" x:k="v"/>', '{"a":{"+@x":"urn:x","+@k":"v"}}', new FormatOptions(xmlKeepNamespace: false)];

        yield 'custom prefixes' => ['<a x="1">t</a>', '{"a":{"~content":"t","@x":"1"}}', new FormatOptions(xmlAttributePrefix: '@', xmlContentName: '~content')];

        yield 'lenient attributes' => ['<a b=c d>x</a>', '{"a":{"+content":"x","+@b":"c","+@d":"d"}}'];

        yield 'crlf is normalised' => ["<a>x\r\ny</a>", '{"a":"x\ny"}'];

        yield 'bom is skipped' => ["\u{FEFF}<a>1</a>", '{"a":"1"}'];
    }

    #[DataProvider('invalidCases')]
    public function testInvalidInput(string $xml, ?FormatOptions $options = null): void
    {
        $this->expectException(FormatException::class);
        $this->decode($xml, $options ?? new FormatOptions());
    }

    /**
     * @return iterable<string, array{string, 1?: FormatOptions}>
     */
    public static function invalidCases(): iterable
    {
        yield 'mismatched end tag' => ['<a><b></a>'];

        yield 'unexpected end tag' => ['</a>'];

        yield 'unclosed element' => ['<a><b></b>'];

        yield 'unterminated comment' => ['<a><!-- x </a>'];

        yield 'unterminated cdata' => ['<a><![CDATA[x</a>'];

        yield 'unterminated processing instruction' => ['<a/><?pi x'];

        yield 'unterminated directive' => ['<!DOCTYPE a'];

        yield 'unterminated start tag' => ['<a b="1"'];

        yield 'unterminated attribute value' => ['<a b="1>x</a>'];

        yield 'strict unknown entity' => ['<a>&bogus;</a>', new FormatOptions(xmlStrictMode: true)];

        yield 'strict unquoted attribute' => ['<a b=c/>', new FormatOptions(xmlStrictMode: true)];

        yield 'strict valueless attribute' => ['<a b/>', new FormatOptions(xmlStrictMode: true)];
    }

    public function testSyntaxErrorNamesTheLine(): void
    {
        $this->expectException(FormatException::class);
        $this->expectExceptionMessageIsOrContains('XML syntax error on line 3: invalid character entity &bogus;');
        $this->decode("<a>\n<b>x</b>\n<c>&bogus;</c></a>", new FormatOptions(xmlStrictMode: true));
    }

    public function testEmptyInputHasNoDocuments(): void
    {
        self::assertSame([], [...new XmlDecoder()->decode("  \n", new FormatOptions())]);
        self::assertSame(FormatEnum::Xml, new XmlDecoder()->format());
        self::assertSame(FormatEnum::Xml, new XmlEncoder()->format());
    }

    #[DataProvider('encodeCases')]
    public function testEncode(string $yaml, string $expected, ?FormatOptions $options = null): void
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            self::assertSame($expected, new XmlEncoder()->encode($document, $options ?? new FormatOptions(), 0));

            return;
        }

        self::fail('no document');
    }

    /**
     * @return iterable<string, array{string, string, 2?: FormatOptions}>
     */
    public static function encodeCases(): iterable
    {
        yield 'simple' => ["cat: purrs\n", "<cat>purrs</cat>\n"];

        yield 'escapes text and attributes' => ["a:\n  +@q: \"x\\\"y\\ty\"\n  +content: \"<&>'\\\"\"\n", "<a q=\"x&#34;y&#x9;y\">&lt;&amp;&gt;&#39;&#34;</a>\n"];

        yield 'arrays repeat the element' => ["a:\n  b: [1, 2]\n  c: [[3, 4]]\n", "<a>\n  <b>1</b>\n  <b>2</b>\n  <c>3</c>\n  <c>4</c>\n</a>\n"];

        yield 'array of maps at the top level' => ["- a: 1\n- b: 2\n", "<a>1</a>\n<b>2</b>\n"];

        yield 'compact' => ["a:\n  +@x: 1\n  b: [1, 2]\n  c: {}\n", "<a x=\"1\"><b>1</b><b>2</b><c></c></a>\n", new FormatOptions(indent: 0)];

        yield 'custom indent' => ["a:\n  b:\n    c: 1\n", "<a>\n    <b>\n        <c>1</c>\n    </b>\n</a>\n", new FormatOptions(indent: 4)];

        yield 'content and children' => ["a:\n  +content: text\n  b: 1\n", "<a>text\n  <b>1</b>\n</a>\n"];

        yield 'processing instructions and directives' => [
            "+p_xml: version=\"1.0\"\n+directive: DOCTYPE a\na:\n  +p_pi: data\n  +p_empty: ''\n  +directive: X\n",
            "<?xml version=\"1.0\"?>\n<!DOCTYPE a>\n<a><?pi data?><?empty?><!X></a>\n",
        ];

        yield 'comments' => [
            "# head\ncat: # line\n  # above\n  dog: x # tail\n  # foot\n# end\n",
            "<!-- head -->\n<!-- line -->\n<cat><!-- above -->\n  <dog>x<!-- tail --></dog><!-- foot -->\n</cat><!-- end -->\n",
        ];

        yield 'null and empty values' => ["a: ~\nb: ''\n", "<a>~</a>\n<b></b>\n"];

        yield 'aliases and merges' => ["base: &b {x: 1}\nm:\n  <<: *b\n  y: 2\n", "<base>\n  <x>1</x>\n</base>\n<m>\n  <x>1</x>\n  <y>2</y>\n</m>\n"];

        yield 'bare scalar' => ["hello & goodbye\n", "hello &amp; goodbye\n"];

        yield 'empty map' => ["{}\n", ''];
    }

    public function testNestedAttributeIsRejected(): void
    {
        $this->expectException(FormatException::class);
        $this->encodeYaml("a:\n  +@x: [1]\n");
    }

    public function testNonMapTopLevelItemIsRejected(): void
    {
        $this->expectException(FormatException::class);
        $this->encodeYaml("- 1\n- 2\n");
    }

    public function testContentMustBeScalar(): void
    {
        $this->expectException(FormatException::class);
        $this->encodeYaml("a:\n  +content: [1]\n");
    }

    public function testRoundTripKeepsCommentsAndLayout(): void
    {
        $xml = "<!-- before -->\n<a x=\"1\"><!-- first -->\n  <b>1</b><!-- after b -->\n  <c>2</c>\n  <c>3</c>\n</a><!-- after -->\n";

        $options = new FormatOptions();
        foreach (new XmlDecoder()->decode($xml, $options) as $document) {
            self::assertSame($xml, new XmlEncoder()->encode($document, $options, 0));
        }
    }

    public function testStrictModeReportsTheLineOfAnUnknownEntity(): void
    {
        $this->assertDecodeFails("<root>\n<a>x</a>\n<item>&writer;</item>\n</root>\n", new FormatOptions(xmlStrictMode: true), 'XML syntax error on line 3: invalid character entity &writer;');
    }

    public function testSyntaxErrorsNameTheLine(): void
    {
        $this->assertDecodeFails("<a>\n<b>\n</a>\n", new FormatOptions(), 'XML syntax error on line 3: element <b> closed by </a>');
    }

    private function assertDecodeFails(string $xml, FormatOptions $options, string $message): void
    {
        try {
            $this->decode($xml, $options);
        } catch (FormatException $formatException) {
            self::assertSame($message, $formatException->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    private function decode(string $xml, FormatOptions $options): Node
    {
        foreach (new XmlDecoder()->decode($xml, $options) as $document) {
            return $document;
        }

        self::fail('no document');
    }

    private function encodeYaml(string $yaml): string
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            return new XmlEncoder()->encode($document, new FormatOptions(), 0);
        }

        self::fail('no document');
    }
}
