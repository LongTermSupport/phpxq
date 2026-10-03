<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Parser;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every expectation was checked against the reference yq (mikefarah v4.54.1, go-yaml).
 *
 * @internal
 */
final class YamlParserTest extends TestCase
{
    #[DataProvider('treeProvider')]
    public function testTree(string $yaml, string $expected): void
    {
        self::assertSame($expected, $this->dump($yaml));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function treeProvider(): iterable
    {
        yield 'plain mapping' => ["a: 1\nb: two\nc: ~\n", '{!!str=a: !!int=1; !!str=b: !!str=two; !!str=c: !!null=~}'];
        yield 'empty values' => ["a:\nb:\n", '{!!str=a: !!null=; !!str=b: !!null=}'];
        yield 'plain sequence' => ["- a\n- 1\n- true\n", '[!!str=a; !!int=1; !!bool=true]'];
        yield 'nested block' => ["a:\n  b:\n    - x\n    - y\n  c: d\n", '{!!str=a: {!!str=b: [!!str=x; !!str=y]; !!str=c: !!str=d}}'];
        yield 'indentless sequence' => ["a:\n- b\n- c\nd: 1\n", '{!!str=a: [!!str=b; !!str=c]; !!str=d: !!int=1}'];
        yield 'compact nested sequence' => ["- - a\n  - b\n- - c\n", '[[!!str=a; !!str=b]; [!!str=c]]'];
        yield 'sequence of mappings' => ["- a: 1\n  b: 2\n- c\n", '[{!!str=a: !!int=1; !!str=b: !!int=2}; !!str=c]'];
        yield 'flow collections' => ["a: [1, 2, [3]]\nb: {x: y, z: }\n", '{!!str=a: flow[!!int=1; !!int=2; flow[!!int=3]]; !!str=b: flow{!!str=x: !!str=y; !!str=z: !!null=}}'];
        yield 'flow multi line' => ["[a,\n b, {c: d}]\n", 'flow[!!str=a; !!str=b; flow{!!str=c: !!str=d}]'];
        yield 'flow single pair' => ["[a: b, c]\n", 'flow[flow{!!str=a: !!str=b}; !!str=c]'];
        yield 'explicit key' => ["? a\n: b\n? c\n", '{!!str=a: !!str=b; !!str=c: !!null=}'];
        yield 'complex key' => ["? - a\n  - b\n: c\n", '{[!!str=a; !!str=b]: !!str=c}'];
        yield 'flow key' => ["[a, b]: c\n", '{flow[!!str=a; !!str=b]: !!str=c}'];
        yield 'single quoted' => ["a: 'it''s'\n", "{!!str=a: !!str/single=it's}"];
        yield 'double quoted escapes' => ['a: "x\ty\n\u00e9\x41\/\\\"' . "\n", "{!!str=a: !!str/double=x\ty\n\u{e9}A/\\}"];
        yield 'double quoted line folding' => ["a: \"one\n  two\n\n  three\"\n", '{!!str=a: !!str/double=one two' . "\n" . 'three}'];
        yield 'double quoted escaped break' => ["a: \"one \\\n  two\"\n", '{!!str=a: !!str/double=one two}'];
        yield 'multi line plain' => ["a: one\n  two\n  three\nb: 1\n", '{!!str=a: !!str=one two three; !!str=b: !!int=1}'];
        yield 'literal' => ["a: |\n  x\n   y\n\n  z\nb: 1\n", '{!!str=a: !!str/literal=x' . "\n" . ' y' . "\n\n" . 'z' . "\n" . '; !!str=b: !!int=1}'];
        yield 'literal strip' => ["a: |-\n  x\n\n", '{!!str=a: !!str/literal=x}'];
        yield 'literal keep' => ["a: |+\n  x\n\nb: 1\n", '{!!str=a: !!str/literal=x' . "\n\n" . '; !!str=b: !!int=1}'];
        yield 'folded' => ["a: >\n  x\n  y\n\n  z\n", '{!!str=a: !!str/folded=x y' . "\n" . 'z' . "\n" . '}'];
        yield 'folded strip with more indented' => ["a: >-\n  x\n    y\n  z\n", '{!!str=a: !!str/folded=x' . "\n" . '  y' . "\n" . 'z}'];
        yield 'explicit indentation indicator' => ["a: |2\n    x\n  y\n", '{!!str=a: !!str/literal=  x' . "\n" . 'y' . "\n" . '}'];
        yield 'top level block scalar at column zero' => ["|\ntext\n  more\n", '!!str/literal=text' . "\n" . '  more' . "\n"];
        yield 'anchors and aliases' => ["a: &x 1\nb: *x\n", '{!!str=a: &x !!int=1; !!str=b: *x}'];
        yield 'anchor on collection' => ["a: &x\n  b: 1\nc: *x\n", '{!!str=a: &x {!!str=b: !!int=1}; !!str=c: *x}'];
        yield 'self referencing alias' => ["a: &a [*a]\n", '{!!str=a: &a flow[*a]}'];
        yield 'merge key tag' => ["a: &x {b: 1}\n<<: *x\n", '{!!str=a: &x flow{!!str=b: !!int=1}; !!merge=<<: *x}'];
        yield 'explicit tags' => ["- !!str 12\n- !custom x\n- !<tag:yaml.org,2002:int> 3\n- ! y\n- !!map {a: b}\n", '[!tag:!!str !!str=12; !tag:!custom !custom=x; !tag:!!int !!int=3; !=y; !tag:!!map flow{!!str=a: !!str=b}]'];
        yield 'tag directive' => ["%TAG !e! tag:example.com,2000:\n---\na: !e!x 1\n", '{!!str=a: !tag:tag:example.com,2000:x tag:example.com,2000:x=1}'];
        yield 'anchor and tag only' => ["a: !!str\nb: &x\n", '{!!str=a: !tag:!!str !!str=; !!str=b: &x !!null=}'];
        yield 'tag and anchor order' => ["a: !!str &x v\nb: &y !!int 3\n", '{!!str=a: &x !tag:!!str !!str=v; !!str=b: &y !tag:!!int !!int=3}'];
        yield 'unicode' => ["é: ü\n", "{!!str=\u{e9}: !!str=\u{fc}}"];
    }

    #[DataProvider('commentProvider')]
    public function testComments(string $yaml, string $expected): void
    {
        self::assertSame($expected, $this->dump($yaml));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commentProvider(): iterable
    {
        yield 'head and line' => ["# h\na: 1 # l\n", '{!!str=a#h(# h): !!int=1#l(# l)}'];
        yield 'multi line head' => ["# h1\n# h2\na: 1\n", '{!!str=a#h(# h1 / # h2): !!int=1}'];
        yield 'comment directly under a value is the next head' => ["a: 1\n# c\nb: 2\n", '{!!str=a: !!int=1; !!str=b#h(# c): !!int=2}'];
        yield 'blank line after makes it a foot' => ["a: 1\n# c\n\nb: 2\n", '{!!str=a#f(# c): !!int=1; !!str=b: !!int=2}'];
        yield 'blank line before makes it a head' => ["a: 1\n\n# c\nb: 2\n", '{!!str=a: !!int=1; !!str=b#h(# c): !!int=2}'];
        yield 'head with blank line after keeps the newline' => ["a: 1\n\n# c\n\nb: 2\n", '{!!str=a: !!int=1; !!str=b#h(# c / ): !!int=2}'];
        yield 'foot at the end of a block' => ["a:\n  b: 1\n  # c\nc: 2\n", '{!!str=a: {!!str=b#f(# c): !!int=1}; !!str=c: !!int=2}'];
        yield 'dedented comment is a head of the next key' => ["a:\n  b: 1\n# c\nc: 2\n", '{!!str=a: {!!str=b: !!int=1}; !!str=c#h(# c): !!int=2}'];
        yield 'foot then head' => ["a:\n  b: 1\n  # c1\n# c2\nc: 2\n", '{!!str=a: {!!str=b#f(# c1): !!int=1}; !!str=c#h(# c2): !!int=2}'];
        yield 'foot of a sequence item' => ["a:\n  - x\n  # c\nb: 1\n", '{!!str=a: [!!str=x#f(# c)]; !!str=b: !!int=1}'];
        yield 'comment after the last entry' => ["a: 1\n# end\n", '{!!str=a#f(# end): !!int=1}'];
        yield 'comment on a key line' => ["a: # lc\n  b: 1\n", '{!!str=a#l(# lc): {!!str=b: !!int=1}}'];
        yield 'comment after a literal header' => ["a: | # hdr\n  text\n", '{!!str=a: !!str/literal=text' . "\n" . '#l(# hdr)}'];
        yield 'comment after a multi line quoted scalar' => ["a: 'b\n  c' # e\nf: 1\n", '{!!str=a: !!str/single=b c#l(# e); !!str=f: !!int=1}'];
        yield 'comment after a multi line plain scalar' => ["a: b\n  c # e\nf: 1\n", '{!!str=a: !!str=b c#l(# e); !!str=f: !!int=1}'];
        yield 'flow comments' => ["a: [1,\n # c\n\n 2]\n", '{!!str=a: flow[!!int=1#f(# c); !!int=2]}'];
        yield 'flow line comments' => ["[a, # in\n b] # out\n", 'flow[!!str=a#l(# in); !!str=b]#l(# out)'];
        yield 'sequence entry comment' => ["- # c\n  a: b\n", '[{!!str=a#h(# c): !!str=b}]'];
        yield 'comment text is kept raw' => ["k: v #  y  \n", '{!!str=k: !!str=v#l(#  y  )}'];
    }

    public function testDocumentHeadAndFootComments(): void
    {
        $docs = $this->parse("# DH1\n\n# DH2\n\n# HA\nka: va\n\n# end\n");

        self::assertCount(1, $docs);
        self::assertSame("# DH1\n\n# DH2\n", $docs[0]->headComment);
        self::assertSame('# HA', $docs[0]->content[0]->content[0]->headComment);
        self::assertSame('# end', $docs[0]->footComment);
    }

    public function testCommentAfterExplicitStartBelongsToTheFirstNode(): void
    {
        $docs = $this->parse("# before\n---\na: 1\n");

        self::assertSame('', $docs[0]->headComment);
        self::assertSame('# before', $docs[0]->content[0]->content[0]->headComment);
        self::assertTrue($docs[0]->explicitStart);
    }

    public function testCommentOnlyStreamYieldsANullDocument(): void
    {
        $docs = $this->parse("# only\n\n");

        self::assertCount(1, $docs);
        $root = $docs[0]->content[0];
        self::assertSame(NodeKindEnum::Scalar, $root->kind);
        self::assertSame('!!null', $root->tag);
        self::assertSame("# only\n", $root->headComment);
    }

    public function testEmptyInputYieldsNoDocument(): void
    {
        self::assertSame([], $this->parse(''));
        self::assertSame([], $this->parse("\n  \n"));
    }

    public function testMultipleDocuments(): void
    {
        $docs = $this->parse("a: 1\n---\nb: 2\n...\n---\nc\n");

        self::assertSame(['{!!str=a: !!int=1}', '{!!str=b: !!int=2}', '!!str=c'], array_map(NodeDump::dump(...), $docs));
        self::assertFalse($docs[0]->explicitStart);
        self::assertTrue($docs[1]->explicitStart);
        self::assertTrue($docs[1]->explicitEnd);
        self::assertTrue($docs[2]->explicitStart);
    }

    public function testDirectivesAreKeptAsText(): void
    {
        $docs = $this->parse("%YAML 1.1\n%TAG !e! tag:example.com,2000:\n---\na\n");

        self::assertSame("%YAML 1.1\n%TAG !e! tag:example.com,2000:", $docs[0]->directives);
    }

    public function testAnchorsStayVisibleInLaterDocuments(): void
    {
        $docs = $this->parse("a: &x 1\n---\nb: *x\n");

        $alias = $docs[1]->content[0]->content[1];
        self::assertSame(NodeKindEnum::Alias, $alias->kind);
        self::assertSame($docs[0]->content[0]->content[1], $alias->aliasTarget);
    }

    public function testAliasTargetIsTheAnchoredNode(): void
    {
        $doc   = $this->parse("a: &x [1]\nb: *x\n")[0];
        $alias = $doc->content[0]->content[3];

        self::assertSame($doc->content[0]->content[1], $alias->aliasTarget);
        self::assertSame('x', $alias->value);
    }

    public function testPositionsAreOneBased(): void
    {
        $root = $this->parse("a: 1\nb:\n  - x\n")[0]->content[0];

        self::assertSame([1, 1], [$root->line, $root->column]);
        self::assertSame([2, 1], [$root->content[2]->line, $root->content[2]->column]);
        self::assertSame([3, 3], [$root->content[3]->line, $root->content[3]->column]);
        self::assertSame([3, 5], [$root->content[3]->content[0]->line, $root->content[3]->content[0]->column]);
    }

    public function testColumnsCountCharactersNotBytes(): void
    {
        $root = $this->parse("\u{e9}\u{e9}: v\n")[0]->content[0];

        self::assertSame(5, $root->content[1]->column);
    }

    public function testAnchorPositionStartsTheNode(): void
    {
        $root = $this->parse("&a b: &c d\n")[0]->content[0];

        self::assertSame(1, $root->content[0]->column);
        self::assertSame(7, $root->content[1]->column);
    }

    public function testCrlfAndBomAreAccepted(): void
    {
        self::assertSame('{!!str=a: !!str=b c; !!str=d: !!int=1}', $this->dump("\xEF\xBB\xBFa: b\r\n  c\r\nd: 1\r\n"));
    }

    public function testStylesAreRecorded(): void
    {
        $root = $this->parse("[a, 'b', \"c\"]\n")[0]->content[0];

        self::assertSame(NodeStyleEnum::Flow, $root->style);
        self::assertSame(NodeStyleEnum::Default, $root->content[0]->style);
        self::assertSame(NodeStyleEnum::SingleQuoted, $root->content[1]->style);
        self::assertSame(NodeStyleEnum::DoubleQuoted, $root->content[2]->style);
    }

    public function testDocumentsAreYieldedLazily(): void
    {
        $generator = new YamlParser()->parse("a: 1\n---\nb: 2\n---\nc: \"unterminated\n");
        $first     = $generator->current();

        self::assertSame('{!!str=a: !!int=1}', NodeDump::dump($first));
        $this->expectException(YamlSyntaxException::class);
        $generator->next();
        $generator->next();
    }

    #[DataProvider('errorProvider')]
    public function testSyntaxErrors(string $yaml, int $line, string $problem): void
    {
        try {
            $this->parse($yaml);
        } catch (YamlSyntaxException $yamlSyntaxException) {
            self::assertSame($line, $yamlSyntaxException->yamlLine);
            self::assertSame(\sprintf('yaml: line %d: %s', $line, $problem), $yamlSyntaxException->getMessage());

            return;
        }

        self::fail('expected a syntax error');
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function errorProvider(): iterable
    {
        yield 'unknown anchor' => ["a: *x\n", 1, "unknown anchor 'x' referenced"];
        yield 'tab where a key should start' => ["\ta: 1\n", 1, 'found character that cannot start any token'];
        yield 'unterminated double quote' => ["a: 1\nb: \"x\n", 2, 'found unexpected end of stream'];
        yield 'unknown escape' => ["a: \"\\q\"\n", 1, 'found unknown escape character'];
        yield 'values not allowed' => ["a: b: c\n", 1, 'mapping values are not allowed in this context'];
        yield 'missing key' => ["a:\n  - b\n - c\n", 3, 'did not find expected key'];
        yield 'entry after mapping' => ["- a\nb: 1\n", 2, "did not find expected '-' indicator"];
        yield 'unclosed flow mapping' => ["{a: 1\n", 2, "did not find expected ',' or '}'"];
        yield 'unclosed flow sequence' => ["[a, b\n", 2, "did not find expected ',' or ']'"];
        yield 'reserved indicator' => ["a: @b\n", 1, 'found character that cannot start any token'];
        yield 'second document needs a marker' => ["a: 1\n...\nb: 2\n", 3, 'did not find expected <document start>'];
        yield 'incompatible version' => ["%YAML 1.2\n---\na\n", 1, 'found incompatible YAML document'];
        yield 'duplicate version' => ["%YAML 1.1\n%YAML 1.1\n---\na\n", 2, 'found duplicate %YAML directive'];
        yield 'undefined tag handle' => ["a: !e!x 1\n", 1, 'found undefined tag handle'];
        yield 'control character' => ["a: \x01\n", 1, 'control characters are not allowed'];
        yield 'bad hex escape' => ["a: \"\\xZZ\"\n", 1, 'did not find expected hexdecimal number'];
        yield 'indentation indicator zero' => ["a: |0\n  x\n", 1, 'found an indentation indicator equal to 0'];
        yield 'simple key spanning lines' => ["a: |\ntext\n", 2, "could not find expected ':'"];
    }

    /**
     * @return list<Node>
     */
    private function parse(string $yaml): array
    {
        return iterator_to_array(new YamlParser()->parse($yaml), false);
    }

    private function dump(string $yaml): string
    {
        $docs = $this->parse($yaml);
        self::assertCount(1, $docs);

        return NodeDump::dump($docs[0]);
    }
}
