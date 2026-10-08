<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\Codec\HclDecoder;
use LTS\PhpXq\Yq\Format\Codec\YamlEncoder;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class HclReaderTest extends TestCase
{
    private const string COMMENTED = "a: 1 # c\n";

    private const string EMPTY_COMMENTED = "a: 1 #\n";

    private const string TWO_ITEMS = "a:\n  - 1\n  - 2\n";

    private const string EXPECTED_LABEL = 'hcl: line 1: expected a block label or { after a';

    private const string EXPECTED_EXPRESSION = 'hcl: line 1: expected an expression';

    private const string NEWLINE_IN_STRING = 'hcl: newline in string literal';

    private const string REPLACEMENT_VALUE = "a: \"\u{FFFD}\"\n";

    #[DataProvider('errorCases')]
    public function testErrorMessages(string $hcl, string $expectedMessage): void
    {
        try {
            $this->decode($hcl);
        } catch (FormatException $formatException) {
            self::assertSame($expectedMessage, $formatException->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorCases(): iterable
    {
        yield 'double equals is not an attribute' => ["a == 1\n", self::EXPECTED_LABEL];

        yield 'double equals without a space' => ["a ==1\n", self::EXPECTED_LABEL];

        yield 'name followed by a name' => ["a b\n", self::EXPECTED_LABEL];

        yield 'name followed by a number' => ["a 1\n", self::EXPECTED_LABEL];

        yield 'bare name' => ["a\n", self::EXPECTED_LABEL];

        yield 'number among the labels' => ["a b 1 {\n}\n", self::EXPECTED_LABEL];

        yield 'bare name on a later line' => ["a = 1\n\n\nb\n", 'hcl: line 4: expected a block label or { after b'];

        yield 'unterminated block' => ["a {\n", 'hcl: line 2: unterminated block'];

        yield 'unexpected closing brace' => ["}\n", 'hcl: line 1: unexpected }'];

        yield 'closing brace as a value' => ["a = }\n", self::EXPECTED_EXPRESSION];

        yield 'missing name' => ["= 1\n", 'hcl: line 1: expected an attribute or block name'];

        yield 'name starting with a digit' => ["1a = 1\n", 'hcl: line 1: expected an attribute or block name'];

        yield 'missing value' => ["a = \n", self::EXPECTED_EXPRESSION];

        yield 'comment as a value' => ["a = # c\n", self::EXPECTED_EXPRESSION];

        yield 'unterminated block comment' => ["/* x\na = 1\n", 'hcl: line 1: unterminated comment'];

        yield 'string over a line break' => ["a = \"line1\nline2\"\n", self::NEWLINE_IN_STRING];

        yield 'string closed after a stray quote' => ["a = \"x\"\"\n", self::NEWLINE_IN_STRING];
    }

    #[DataProvider('tooDeepProvider')]
    public function testNestingBeyondTheLimitIsRefused(string $hcl): void
    {
        $this->expectException(FormatException::class);
        $this->expectExceptionMessageIsOrContains(Node::depthError());

        $this->decode($hcl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tooDeepProvider(): iterable
    {
        $depth = Node::maxDepth() + 1;

        yield 'lists' => ['a = ' . str_repeat('[', $depth) . str_repeat(']', $depth) . "\n"];
        yield 'objects' => ['a = ' . str_repeat('{a=', $depth) . '1' . str_repeat('}', $depth) . "\n"];
        yield 'blocks' => [str_repeat('a {', $depth) . str_repeat('}', $depth) . "\n"];
    }

    #[DataProvider('shapeCases')]
    public function testShapes(string $hcl, string $expectedYaml): void
    {
        $yaml = new YamlEncoder()->encode($this->decode($hcl), new FormatOptions(), 0);

        self::assertSame($expectedYaml, $yaml);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapeCases(): iterable
    {
        yield 'attribute with a quoted value and no spaces' => ["a=\"x\"\n", "a: \"x\"\n"];

        yield 'attribute with a hash comment and extra spaces' => ["a   =   1   # c\n", self::COMMENTED];

        yield 'attribute with a slash comment' => ["a = 1 // c\n", self::COMMENTED];

        yield 'attribute with a comment without a space' => ["a = 1 #c\n", self::COMMENTED];

        yield 'attribute with an empty hash comment' => ["a = 1 #\n", self::EMPTY_COMMENTED];

        yield 'attribute with an empty slash comment' => ["a = 1 //\n", self::EMPTY_COMMENTED];

        yield 'attribute with a blank slash comment' => ["a = 1 //   \n", self::EMPTY_COMMENTED];

        yield 'head comments' => ["# one\n# two\na = 1\n", "# one\n# two\na: 1\n"];

        yield 'slash head comments' => ["// one\n// two\na = 1\n", "# one\n# two\na: 1\n"];

        yield 'empty head comment' => ["#\na = 1\n", "#\na: 1\n"];

        yield 'block comment' => ["/* block */\na = 1\n", "# block\na: 1\n"];

        yield 'multi-line block comment' => ["/*\n * x\n * y\n */\na = 1\n", "# x\n# y\na: 1\n"];

        yield 'two block comments on a line' => ["/* a */ /* b */\na = 1\n", "# a\n# b\na: 1\n"];

        yield 'empty block comment' => ["/**/\na = 1\n", "# \na: 1\n"];

        yield 'comment after an empty block is dropped' => ["a {\n} # c\nb = 1\n", "a: {}\nb: 1\n"];

        yield 'comment after an empty one-line block is dropped' => ["a { } # c\nb = 1\n", "a: {}\nb: 1\n"];

        yield 'comment after a block with content is dropped' => ["a {\n  x = 1\n} // c\nb = 1\n", "a:\n  x: 1\nb: 1\n"];

        yield 'comment alone in a block moves to the end' => ["a {\n # only\n}\n", "a: {}\n# only\n"];

        yield 'comment alone in a block before a sibling' => ["a {\n # only\n}\nb = 1\n", "a: {}\n# only\nb: 1\n"];

        yield 'foot comment of a block' => ["a {\n  x = 1\n  # foot\n}\n", "a:\n  x: 1\n  # foot\n"];

        yield 'foot comment of a block with two attributes' => ["a {\n  x = 1\n  y = 2\n  # foot\n}\n", "a:\n  x: 1\n  y: 2\n  # foot\n"];

        yield 'document foot comment alone' => ["# foot\n", "{}\n# foot\n"];

        yield 'document foot comment' => ["a = 1\n# foot\n", "a: 1\n# foot\n"];

        yield 'repeated attribute keeps the last value' => ["a = 1\na = 2\n", "a: 2\n"];

        yield 'repeated second attribute' => ["a = 1\nb = 2\nb = 3\n", "a: 1\nb: 3\n"];

        yield 'repeated first attribute keeps its place' => ["a = 1\nb = 2\na = 3\n", "a: 3\nb: 2\n"];

        yield 'numeric-looking block labels stay distinct' => ["x \"1\" { v = 1 }\nx \"01\" { v = 2 }\nx \"1\" { w = 3 }\n", "x:\n  \"1\":\n    w: 3\n  \"01\":\n    v: 2\n"];

        yield 'repeated third attribute' =>["a = 1\nb = 2\nc = 3\nc = 4\n", "a: 1\nb: 2\nc: 4\n"];

        yield 'bare label' => ["a b {\n}\n", "a:\n  b: {}\n"];

        yield 'quoted label' => ["a \"b\" {\n}\n", "a:\n  b: {}\n"];

        yield 'quoted and bare labels' => ["a \"b\" c {\n  x = 1\n}\n", "a:\n  b:\n    c:\n      x: 1\n"];

        yield 'blocks sharing two labels' => [
            "a \"b\" \"c\" {\n  x = 1\n}\na \"b\" \"d\" {\n  y = 2\n}\n",
            "a:\n  b:\n    c:\n      x: 1\n    d:\n      y: 2\n",
        ];

        yield 'blocks sharing one label' => ["a \"b\" {\n  x = 1\n}\na \"c\" {\n  y = 2\n}\n", "a:\n  b:\n    x: 1\n  c:\n    y: 2\n"];

        yield 'head comment on a labelled block' => ["# head\na \"b\" {\n  x = 1\n}\n", "# head\na:\n  b:\n    x: 1\n"];

        yield 'head comment on a block with two labels' => ["# head\na \"b\" \"c\" {\n  x = 1\n}\n", "# head\na:\n  b:\n    c:\n      x: 1\n"];

        yield 'three unlabelled blocks become a sequence' => ["a {\n x = 1\n}\na {\n x = 2\n}\na {\n x = 3\n}\n", "a:\n  - x: 1\n  - x: 2\n  - x: 3\n"];

        yield 'two one-line blocks become a sequence' => ["a { x = 1 }\na { x = 2 }\n", "a:\n  - x: 1\n  - x: 2\n"];

        yield 'empty block' => ["a {\n}\n", "a: {}\n"];

        yield 'label with an escape' => ["a \"b\\n\" {\n}\n", "a:\n  ? |\n    b\n  : {}\n"];

        yield 'label with unicode and tab escapes' => ["a \"\\u00e9\\t\" {\n}\n", "a:\n  \"\u{E9}\\t\": {}\n"];

        yield 'string escapes' => ["a = \"x\\ty\\\"z\\\\w\\rq\\nr\"\n", "a: \"x\\ty\\\"z\\\\w\\rq\\nr\"\n"];

        yield 'unicode escapes' => ["a = \"\\u00e9\\U0001F600\"\n", "a: \"\u{E9}\\U0001F600\"\n"];

        yield 'zero code point becomes the replacement character' => ["a = \"\\u0000\"\n", self::REPLACEMENT_VALUE];

        yield 'high surrogate becomes the replacement character' => ["a = \"\\uD800\"\n", self::REPLACEMENT_VALUE];

        yield 'low surrogate becomes the replacement character' => ["a = \"\\uDFFF\"\n", self::REPLACEMENT_VALUE];

        yield 'code point past the range becomes the replacement character' => ["a = \"\\U00110000\"\n", self::REPLACEMENT_VALUE];

        yield 'code point just below the surrogates' => ["a = \"\\uD7FF\"\n", "a: \"\u{D7FF}\"\n"];

        yield 'code point just above the surrogates' => ["a = \"\\uE000\"\n", "a: \"\u{E000}\"\n"];

        yield 'largest code point' => ["a = \"\\U0010FFFF\"\n", "a: \"\\U0010FFFF\"\n"];

        yield 'two strings joined by a plus stay raw' => ["a = \"x\" + \"y\"\n", "a: '\"x\" + \"y\"'\n"];

        yield 'two adjacent strings stay raw' => ["a = \"x\" \"y\"\n", "a: '\"x\" \"y\"'\n"];

        yield 'two strings separated by a comma stay raw' => ["a = \"x\", \"y\"\n", "a: '\"x\", \"y\"'\n"];

        yield 'empty string' => ["a = \"\"\n", "a: \"\"\n"];

        yield 'integers' => ["a = 0\nb = 10\nc = -1\n", "a: 0\nb: 10\nc: -1\n"];

        yield 'negative zero' => ["a = -0\n", "a: !!int -0\n"];

        yield 'leading zero stays text' => ["a = 01\n", "a: \"01\"\n"];

        yield 'floats' => ["a = 1.5\nb = -1.5\nc = 1e5\nd = 1.5E-3\n", "a: 1.5\nb: -1.5\nc: 1e5\nd: 1.5E-3\n"];

        yield 'trailing dot stays text' => ["a = 1.\n", "a: \"1.\"\n"];

        yield 'leading dot stays text' => ["a = .5\n", "a: \".5\"\n"];

        yield 'booleans and null' => ["a = true\nb = false\nc = null\n", "a: true\nb: false\nc: null\n"];

        yield 'word starting with true' => ["a = truex\n", "a: truex\n"];

        yield 'list' => ["a = [1, 2]\n", self::TWO_ITEMS];

        yield 'list added to a list stays raw' => ["a = [1, 2] + [3]\n", "a: '[1, 2] + [3]'\n"];

        yield 'list with a comment inside stays raw' => ["a = [1, # c\n 2]\n", "a: |-\n  [1, # c\n   2]\n"];

        yield 'for expression in a list' => ["a = [for x in y : x]\n", "a: '[for x in y : x]'\n"];

        yield 'for expression after a space' => ["a = [ for x in y : x]\n", "a: '[ for x in y : x]'\n"];

        yield 'for expression after a newline' => ["a = [\nfor x in y : x]\n", "a: |-\n  [\n  for x in y : x]\n"];

        yield 'list of references' => ["a = [foo, bar]\n", "a:\n  - foo\n  - bar\n"];

        yield 'list item that only starts like for' => ["a = [ foreign ]\n", "a:\n  - foreign\n"];

        yield 'for expression in an object' => ["a = {for k, v in x : k => v}\n", "a: '{for k, v in x : k => v}'\n"];

        yield 'for expression in an object after a space' => ["a = { for k, v in x : k => v}\n", "a: '{ for k, v in x : k => v}'\n"];

        yield 'object over lines' => ["a = {\n  x = 1\n  y = \"two\"\n}\n", "a:\n  x: 1\n  y: \"two\"\n"];

        yield 'object on a line' => ["a = {x = 1, y = 2}\n", "a:\n  x: 1\n  y: 2\n"];

        yield 'object with quoted keys and colons' => ["a = {\"x\" = 1, \"y z\" : 2, w: 3}\n", "a:\n  x: 1\n  y z: 2\n  w: 3\n"];

        yield 'two objects added stay raw' => ["a = {x = 1} + {y = 2}\n", "a: '{x = 1} + {y = 2}'\n"];

        yield 'object entry without a value stays raw' => ["a = {x = 1, nope}\n", "a: '{x = 1, nope}'\n"];

        yield 'object value on the next line stays raw' => ["a = {x =\n 1}\n", "a: |-\n  {x =\n   1}\n"];

        yield 'nested objects and lists' => ["a = {x = {y = [1, {z = 2}]}}\n", "a:\n  x:\n    y:\n      - 1\n      - z: 2\n"];

        yield 'object entry with extra spaces' => ["a = {x   =   1   }\n", "a:\n  x: 1\n"];

        yield 'object key with an escaped quote' => ["a = {\"q\\\"r\" = 1}\n", "a:\n  q\"r: 1\n"];

        yield 'object key with a unicode escape' => ["a = {\"q\\u00e9\" = 1}\n", "a:\n  q\u{E9}: 1\n"];

        yield 'list strings holding delimiters' => ["a = [\"a,b\", \"c\"]\n", "a:\n  - \"a,b\"\n  - \"c\"\n"];

        yield 'list strings holding brackets' => ["a = [\"a]\", [1]]\n", "a:\n  - \"a]\"\n  - - 1\n"];

        yield 'list of objects' => ["a = [{x = 1}, {y = 2}]\n", "a:\n  - x: 1\n  - y: 2\n"];

        yield 'list with a parenthesised item' => ["a = [(1 + 2), 3]\n", "a:\n  - (1 + 2)\n  - 3\n"];

        yield 'empty list' => ["a = []\n", "a: []\n"];

        yield 'empty object' => ["a = {}\n", "a: {}\n"];

        yield 'list of only a comma' => ["a = [,]\n", "a: []\n"];

        yield 'list with a doubled comma' => ["a = [1,,2]\n", self::TWO_ITEMS];

        yield 'parenthesised arithmetic' => ["a = (1 + 2)\n", "a: (1 + 2)\n"];

        yield 'function call' => ["a = foo(1, 2)\n", "a: foo(1, 2)\n"];

        yield 'reference' => ["a = var.b\n", "a: var.b\n"];

        yield 'heredoc' => ["a = <<EOT\nhello\nEOT\n", "a: |-\n  <<EOT\n  hello\n  EOT\n"];

        yield 'indented heredoc followed by an attribute' => ["a = <<-EOT\n  hello\n  EOT\nb = 1\n", "a: |-\n  <<-EOT\n    hello\n    EOT\nb: 1\n"];

        yield 'list over lines' => ["a = [\n  1,\n  2,\n]\n", self::TWO_ITEMS];

        yield 'list over lines followed by an attribute' => ["a = [\n  \"x\",\n  \"y\",\n]\nb = 1\n", "a:\n  - \"x\"\n  - \"y\"\nb: 1\n"];

        yield 'trailing comments on two attributes' => ["a = \"x\" # c\nb = 2 // d\n", "a: \"x\" # c\nb: 2 # d\n"];

        yield 'byte order mark' => ["\u{FEFF}a = 1\n", "a: 1\n"];

        yield 'carriage returns' => ["a = 1\r\nb = 2\r\n", "a: 1\nb: 2\n"];

        yield 'block comment after a string stays in the expression' => ["a = \"x\" /* c */\n", "a: '\"x\" /* c */'\n"];

        yield 'block comment between operands' => ["a = 1 /* c */ + 2\n", "a: 1 /* c */ + 2\n"];

        yield 'trailing comment after a list' => ["a = [1] # c\n", "a: # c\n  - 1\n"];

        yield 'trailing comment after an object' => ["a = { x = 1 } # c\n", "a: # c\n  x: 1\n"];

        yield 'trailing slash comment after a list' => ["a = [\"x\"] // c\n", "a: # c\n  - \"x\"\n"];

        yield 'brace inside an object string' => ["a = {x = \"}\"}\n", "a:\n  x: \"}\"\n"];

        yield 'bracket inside a list string' => ["a = [\"[\"]\n", "a:\n  - \"[\"\n"];
    }

    private function decode(string $hcl): Node
    {
        foreach (new HclDecoder()->decode($hcl, new FormatOptions()) as $document) {
            return $document;
        }

        self::fail('no document');
    }
}
