<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\Codec\XmlDecoder;
use LTS\PhpXq\Yq\Format\Codec\YamlEncoder;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class XmlReaderTest extends TestCase
{
    private const string EOF = 'unexpected EOF';

    private const string BAD_FOO = 'invalid character entity &foo;';

    private const string START_EOF = 'unexpected EOF in start tag';

    private const string NULL_A = '{"a":null}';

    private const string A_OPEN = '{"a":"';

    #[DataProvider('errorCases')]
    public function testErrorMessages(string $xml, int $line, string $detail, ?FormatOptions $options = null): void
    {
        try {
            $this->decode($xml, $options ?? new FormatOptions());
        } catch (FormatException $exception) {
            self::assertSame(\sprintf('XML syntax error on line %d: %s', $line, $detail), $exception->getMessage());
            self::assertSame(0, $exception->getCode());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{string, int, string, 3?: FormatOptions}>
     */
    public static function errorCases(): iterable
    {
        $strict = new FormatOptions(xmlStrictMode: true);

        yield 'unclosed element' => ['<a>x', 1, self::EOF];

        yield 'unclosed element on a later line' => ["<a>\n\n<b>", 3, self::EOF];

        yield 'mismatched end tag' => ['<a><b></a>', 1, 'element <b> closed by </a>'];

        yield 'mismatched end tag on the second line' => ["<a>\n</b>", 2, 'element <a> closed by </b>'];

        yield 'end tag without a start tag' => ['</a>', 1, 'unexpected end element </a>'];

        yield 'end tag without a start tag keeps its name' => ["<a/>\n</zed>", 2, 'unexpected end element </zed>'];

        yield 'unterminated comment' => ['<a><!-- c', 1, 'unexpected EOF in comment'];

        yield 'unterminated cdata' => ['<a><![CDATA[x', 1, 'unexpected EOF in CDATA section'];

        yield 'unterminated processing instruction' => ['<a><?pi', 1, 'unexpected EOF in processing instruction'];

        yield 'processing instruction opener only' => ['<?>', 1, 'unexpected EOF in processing instruction'];

        yield 'processing instruction without a target' => ['<? x?><a/>', 1, 'expected target name after <?'];

        yield 'unterminated directive' => ['<a><!DOCTYPE', 1, 'unexpected EOF in directive'];

        yield 'unterminated directive comment' => ['<a><!DOCTYPE x <!-- y', 1, 'unexpected EOF in directive comment'];

        yield 'unterminated start tag' => ['<a', 1, self::START_EOF];

        yield 'unterminated start tag with an attribute name' => ['<a b', 1, self::START_EOF];

        yield 'attribute without a value at the end' => ['<a b=', 1, self::START_EOF];

        yield 'unterminated attribute value' => ['<a b="x', 1, 'unexpected EOF in attribute value'];

        yield 'unterminated attribute value before a bracket' => ['<a b="x>', 1, 'unexpected EOF in attribute value'];

        yield 'space after the opening bracket' => ['< a/>', 1, 'expected element name after <'];

        yield 'empty tag' => ['<>', 1, 'expected element name after <'];

        yield 'slash without a bracket' => ['<a/ >', 1, 'expected /> in element'];

        yield 'unquoted attribute value with a spaced slash' => ['<a b=c/ >', 1, self::EOF];

        yield 'unterminated end tag' => ['<a></a', 1, 'unexpected EOF in end tag'];

        yield 'unterminated start tag on a later line' => ["\n\n<a", 3, self::START_EOF];

        yield 'mismatch on a later line' => ["<a>\n<b>\n</a>", 3, 'element <b> closed by </a>'];

        yield 'unquoted attribute value in strict mode' => ['<a b=c>x</a>', 1, 'unquoted or missing attribute value in element', $strict];

        yield 'attribute without equals in strict mode' => ['<a b>x</a>', 1, 'attribute name without = in element', $strict];

        yield 'zero character reference in strict mode' => ['<a>&#0;</a>', 1, 'invalid character entity &#0;', $strict];

        yield 'surrogate character reference in strict mode' => ['<a>&#xD800;</a>', 1, 'invalid character entity &#xD800;', $strict];

        yield 'last surrogate character reference in strict mode' => ['<a>&#xDFFF;</a>', 1, 'invalid character entity &#xDFFF;', $strict];

        yield 'out of range character reference in strict mode' => ['<a>&#x110000;</a>', 1, 'invalid character entity &#x110000;', $strict];

        yield 'unknown entity in strict mode' => ['<a>&foo;</a>', 1, self::BAD_FOO, $strict];

        yield 'unknown entity on the third line' => ["<a>\n\n&foo;</a>", 3, self::BAD_FOO, $strict];

        yield 'unknown entity after a known one' => ["<a>\n&amp;\n&foo;</a>", 3, self::BAD_FOO, $strict];

        yield 'unknown entity after carriage returns' => ["<a>\r\n\r\n&foo;</a>", 3, self::BAD_FOO, $strict];

        yield 'unknown entity in a later element' => ["<a>x\ny</a>\n<b>&foo;</b>", 3, self::BAD_FOO, $strict];

        yield 'unknown entity in an attribute' => ["<a b='\n&foo;'/>", 2, self::BAD_FOO, $strict];

        yield 'unknown entity in an attribute on one line' => ['<a x="&foo;"/>', 1, self::BAD_FOO, $strict];

        yield 'comment opener that overlaps its closer' => ['<a><!--></a>', 1, 'unexpected EOF in comment'];

        yield 'processing instruction closer right after the question mark' => ['<a><??></a>', 1, 'expected target name after <?'];

        yield 'empty end tag' => ['<a></>', 1, 'element <a> closed by </>'];

        yield 'attribute starting with an equals sign' => ['<a ="x"/>', 1, 'expected attribute name in element'];

        yield 'unknown entity after the same word' => ["<a>foo\n&foo;</a>", 2, self::BAD_FOO, $strict];

        yield 'unterminated element after a byte order mark' => ["\u{FEFF}<a>x", 1, self::EOF];
    }

    /**
     */
    #[DataProvider('shapeCases')]
    public function testShapes(string $xml, string $expectedJson, ?FormatOptions $options = null): void
    {
        $json = new JsonEncoder()->encode($this->decode($xml, $options ?? new FormatOptions()), new FormatOptions(indent: 0, unwrapScalar: false), 0);

        self::assertSame($expectedJson . "\n", $json);
    }

    /**
     * @return iterable<string, array{string, string, 2?: FormatOptions}>
     */
    public static function shapeCases(): iterable
    {
        $strict   = new FormatOptions(xmlStrictMode: true);
        $resolved = new FormatOptions(xmlRawToken: false);

        yield 'unquoted attribute value followed by text after the self-closing tag' => ['<a b=c/>x', '{"+content":"x","a":{"+@b":"c"}}'];

        yield 'unquoted attribute value' => ['<a b=c>x</a>', '{"a":{"+content":"x","+@b":"c"}}'];

        yield 'unquoted attribute value before a self-closing slash' => ['<a b=c/>', '{"a":{"+@b":"c"}}'];

        yield 'longer unquoted attribute value before a self-closing slash' => ['<a b=cd/>', '{"a":{"+@b":"cd"}}'];

        yield 'unquoted attribute value containing slashes' => ['<a b=x/y/>', '{"a":{"+@b":"x/y"}}'];

        yield 'empty unquoted attribute value before a self-closing slash' => ['<a b=/>', '{"a":{"+@b":""}}'];

        yield 'two unquoted attributes' => ['<a b=c d=e/>', '{"a":{"+@b":"c","+@d":"e"}}'];

        yield 'unquoted attribute after spaces' => ['<a b= c>x</a>', '{"a":{"+content":"x","+@b":"c"}}'];

        yield 'attribute without a value repeats its name' => ['<a b>x</a>', '{"a":{"+content":"x","+@b":"b"}}'];

        yield 'attribute without a value before a space' => ['<a b >x</a>', '{"a":{"+content":"x","+@b":"b"}}'];

        yield 'attribute with newlines around the equals' => ["<a b\n=\n'1'>x</a>", '{"a":{"+content":"x","+@b":"1"}}'];

        yield 'attributes with spaces around the equals' => ["<a b = \"1\" c = '2'/>", '{"a":{"+@b":"1","+@c":"2"}}'];

        yield 'attributes across lines' => ["<a x=\"1\"\ny=\"2\"/>", '{"a":{"+@x":"1","+@y":"2"}}'];

        yield 'duplicate attributes become an array' => ['<a b="1" b="2"/>', '{"a":{"+@b":["1","2"]}}'];

        yield 'attribute quotes inside the value' => ['<a x=\'a"b\' y="a\'b" z="a>b"/>', '{"a":{"+@x":"a\"b","+@y":"a\'b","+@z":"a>b"}}'];

        yield 'entities in an attribute' => ['<a x="&amp;&foo;"/>', '{"a":{"+@x":"&&foo;"}}'];

        yield 'custom attribute prefix' => ['<a x="1"><b y="2"/></a>', '{"a":{"@x":"1","b":{"@y":"2"}}}', new FormatOptions(xmlAttributePrefix: '@')];

        yield 'custom content name' => ['<a x="1">t</a>', '{"a":{"c":"t","+@x":"1"}}', new FormatOptions(xmlContentName: 'c')];

        yield 'zero character reference stays as written' => ['<a>&#0;</a>', '{"a":"&#0;"}'];

        yield 'character reference forms' => ['<a>&#65;&#x41;&#X41;</a>', '{"a":"AA&#X41;"}'];

        yield 'astral hex character reference' => ['<a>&#x1F600;</a>', self::A_OPEN . "\u{1F600}" . '"}'];

        yield 'astral decimal character reference' => ['<a>&#128512;</a>', self::A_OPEN . "\u{1F600}" . '"}'];

        yield 'largest character reference' => ['<a>&#x10FFFF;</a>', self::A_OPEN . "\u{10FFFF}" . '"}'];

        yield 'character reference past the range' => ['<a>&#x110000;</a>', '{"a":"&#x110000;"}'];

        yield 'character reference just below the surrogates' => ['<a>&#xD7FF;</a>', self::A_OPEN . "\u{D7FF}" . '"}'];

        yield 'first surrogate reference stays as written' => ['<a>&#xD800;</a>', '{"a":"&#xD800;"}'];

        yield 'last surrogate reference stays as written' => ['<a>&#xDFFF;</a>', '{"a":"&#xDFFF;"}'];

        yield 'character reference just above the surrogates' => ['<a>&#xE000;</a>', self::A_OPEN . "\u{E000}" . '"}'];

        yield 'low character reference is allowed in strict mode' => ['<a>&#1;</a>', '{"a":"\u0001"}', $strict];

        yield 'known entity is allowed in strict mode' => ['<a>&amp;</a>', '{"a":"&"}', $strict];

        yield 'empty character references stay as written' => ['<a>&#x;&#;</a>', '{"a":"&#x;&#;"}'];

        yield 'carriage returns in text become newlines' => ["<a>x\r\ny\rz</a>", '{"a":"x\ny\nz"}'];

        yield 'carriage returns in cdata become newlines' => ["<a><![CDATA[x\r\ny\rz]]></a>", '{"a":"x\ny\nz"}'];

        yield 'empty cdata' => ['<a><![CDATA[]]></a>', self::NULL_A];

        yield 'cdata is trimmed' => ['<a><![CDATA[ x ]]></a>', '{"a":"x"}'];

        yield 'cdata with a bracket pair inside' => ['<a><![CDATA[a]]b]]></a>', '{"a":"a]]b"}'];

        yield 'processing instruction with trailing space' => ['<a><?pi x ?></a>', '{"a":{"+p_pi":"x "}}'];

        yield 'processing instruction with a tab' => ["<a><?pi\tx?></a>", '{"a":{"+p_pi":"x"}}'];

        yield 'processing instruction without data' => ['<a><?pi?></a>', '{"a":{"+p_pi":""}}'];

        yield 'skipped processing instruction' => ['<a><?pi x?></a>', self::NULL_A, new FormatOptions(xmlSkipProcInst: true)];

        yield 'directive' => ['<a><!DOCTYPE x></a>', '{"a":{"+directive":"DOCTYPE x"}}'];

        yield 'directive with quoted brackets' => ['<a><!X "a>b" \'c>d\'></a>', '{"a":{"+directive":"X \"a>b\" \'c>d\'"}}'];

        yield 'directive with a nested declaration' => ['<a><!X [<!Y>]></a>', '{"a":{"+directive":"X [<!Y>]"}}'];

        yield 'directive with two nested levels' => ['<a><!X [<!Y [<!Z>]>]></a>', '{"a":{"+directive":"X [<!Y [<!Z>]>]"}}'];

        yield 'directive with a comment inside' => ['<a><!X [<!-- c -->]></a>', '{"a":{"+directive":"X [ ]"}}'];

        yield 'directive with a comment right after the name' => ['<a><!X<!-- c -->y></a>', '{"a":{"+directive":"X y"}}'];

        yield 'directive with an empty comment' => ['<a><!X<!----> y></a>', '{"a":{"+directive":"X  y"}}'];

        yield 'directive with a quote closed in the middle' => ['<a><!X "a"b></a>', '{"a":{"+directive":"X \"a\"b"}}'];

        yield 'directive with a double quote inside single quotes' => ['<a><!X \'a"b\'></a>', '{"a":{"+directive":"X \'a\"b\'"}}'];

        yield 'directive with a single quote inside double quotes' => ['<a><!X "a\'b"></a>', '{"a":{"+directive":"X \"a\'b\""}}'];

        yield 'skipped directive' => ['<a><!X y></a>', self::NULL_A, new FormatOptions(xmlSkipDirectives: true)];

        yield 'end tag with trailing spaces' => ['<a></a  >', self::NULL_A];

        yield 'end tag with a leading space' => ['<a></ a>', self::NULL_A];

        yield 'text after the root element' => ['<a>text</a>trail', '{"+content":"trail","a":"text"}'];

        yield 'text around the root element' => ['x <a/> y', '{"+content":["x","y"],"a":null}'];

        yield 'plain text only' => ['junk', '"junk"'];

        yield 'two roots' => ["<a>x</a>\n<b>y</b>\n", '{"a":"x","b":"y"}'];

        yield 'mixed content' => ["<a>\n  <b>1</b>\n  text\n</a>", '{"a":{"+content":"text","b":"1"}}'];

        yield 'byte order mark' => ["\u{FEFF}<a/>", self::NULL_A];

        yield 'namespaces are kept raw by default' => ['<a:b xmlns:a="u"/>', '{"a:b":{"+@xmlns:a":"u"}}'];

        yield 'namespaces dropped' => ['<a:b xmlns:a="u"/>', '{"b":{"+@a":"u"}}', new FormatOptions(xmlKeepNamespace: false)];

        yield 'resolved prefixes' => ['<a:b xmlns:a="u" a:c="1" d="2"/>', '{"u:b":{"+@xmlns:a":"u","+@u:c":"1","+@d":"2"}}', $resolved];

        yield 'resolved default namespace' => ['<b xmlns="dd"/>', '{"dd:b":{"+@xmlns":"dd"}}', $resolved];

        yield 'default namespace declared after the other attributes' => ['<a:b xmlns:a="u" a:c="1" d="2" xmlns="dd"/>', '{"u:b":{"+@xmlns:a":"u","+@u:c":"1","+@d":"2","+@xmlns":"dd"}}', $resolved];

        yield 'empty default namespace' => ['<b xmlns=""/>', '{"b":{"+@xmlns":""}}', $resolved];

        yield 'unknown prefix stays as the namespace' => ['<q:b/>', '{"q:b":null}', $resolved];

        yield 'xml prefix element' => ['<xml:b/>', '{"http://www.w3.org/XML/1998/namespace:b":null}', $resolved];

        yield 'xml prefix attribute' => ['<b xml:lang="en"/>', '{"b":{"+@http://www.w3.org/XML/1998/namespace:lang":"en"}}', $resolved];

        yield 'unknown prefix attribute' => ['<b q:lang="en"/>', '{"b":{"+@q:lang":"en"}}', $resolved];

        yield 'declared prefix attribute' => ['<b xmlns:q="u" q:lang="en"/>', '{"b":{"+@xmlns:q":"u","+@u:lang":"en"}}', $resolved];

        yield 'element name starting with a colon' => ['<:b/>', '{":b":null}', $resolved];

        yield 'attribute name starting with a colon' => ['<b :c="1"/>', '{"b":{"+@:c":"1"}}', $resolved];

        yield 'default namespace and prefix together' => ['<b xmlns:q="u" xmlns="d" c="1"/>', '{"d:b":{"+@xmlns:q":"u","+@xmlns":"d","+@c":"1"}}', $resolved];
    }

    #[DataProvider('commentCases')]
    public function testComments(string $xml, string $expectedYaml): void
    {
        $yaml = new YamlEncoder()->encode($this->decode($xml, new FormatOptions()), new FormatOptions(), 0);

        self::assertSame($expectedYaml, $yaml);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commentCases(): iterable
    {
        yield 'two comments after text join into the line comment' => ['<a>t<!--1--><!--2--></a>', "a: t # 1 2\n"];

        yield 'one comment after text is the line comment' => ['<a>t<!--1--></a>', "a: t # 1\n"];

        yield 'two comments before a child join into the head comment' => ['<a><!--1--><!--2--><b/></a>', "a:\n  # 1\n  # 2\n  b:\n"];

        yield 'comments after a child are foot comments' => ['<a><!--1--><b/><!--2--><!--3--></a>', "a:\n  # 1\n  b:\n  # 2\n  # 3\n"];

        yield 'comment after a child with text' => ['<a><b>t</b><!--x--></a>', "a:\n  b: t\n  # x\n"];

        yield 'comment between two children' => ['<a><b>t</b><!--x--><c>u</c></a>', "a:\n  b: t\n  # x\n\n  c: u\n"];

        yield 'comment between text pieces' => ['<a>t<!--x-->u<!--y--></a>', "a: # x y\n  - t\n  - u\n"];

        yield 'comment before the root' => ['<!--x--><a/>', "# x\na:\n"];

        yield 'comment after the root' => ['<a/><!--x-->', "a:\n# x\n"];

        yield 'comment text is kept as written between the markers' => ['<a><!-- x --><b/></a>', "a:\n  # x\n  b:\n"];

        yield 'empty comment is dropped' => ['<a><!----><b/></a>', "a:\n  b:\n"];

        yield 'comment starting with a dash' => ['<a><!---x--><b/></a>', "a:\n  # -x\n  b:\n"];
    }

    private function decode(string $xml, FormatOptions $options): \LTS\PhpXq\Yaml\Node
    {
        foreach (new XmlDecoder()->decode($xml, $options) as $document) {
            return $document;
        }

        self::fail('no document');
    }
}
