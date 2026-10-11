<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Emitter;

use LogicException;
use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class YamlEmitterTest extends TestCase
{
    #[DataProvider('blockCases')]
    public function testEmitsLikeGoYaml(Node $node, string $expected): void
    {
        self::assertSame($expected, new YamlEmitter()->emit($node));
    }

    /**
     * @return iterable<string, array{Node, string}>
     */
    public static function blockCases(): iterable
    {
        yield 'flat mapping' => [self::map(['a', '1'], ['b', 'two']), "a: 1\nb: two\n"];

        yield 'plain lines before and after nested blocks keep their indentation' => [
            Node::mapping([
                Node::scalar('a'), Node::scalar('1'),
                Node::scalar('b'), Node::mapping([
                    Node::scalar('c'), Node::scalar('2'),
                    Node::scalar('d'), Node::sequence([Node::scalar('x'), Node::scalar('y')]),
                    Node::scalar('e'), Node::scalar('3'),
                    Node::scalar('f'), Node::scalar('4'),
                ]),
                Node::scalar('g'), Node::scalar('5'),
                Node::scalar('h'), Node::scalar('6'),
            ]),
            "a: 1\nb:\n  c: 2\n  d:\n    - x\n    - y\n  e: 3\n  f: 4\ng: 5\nh: 6\n",
        ];

        yield 'a head comment on a later key interrupts the plain-line run' => [
            Node::mapping([
                Node::scalar('a'), Node::scalar('1'),
                self::commented(Node::scalar('b'), 'about b'), Node::scalar('2'),
                Node::scalar('c'), Node::scalar('3'),
            ]),
            "a: 1\n# about b\nb: 2\nc: 3\n",
        ];

        yield 'plain sequence items after a nested item' => [
            Node::sequence([
                Node::scalar('a'),
                Node::scalar('b'),
                Node::sequence([Node::scalar('c')]),
                Node::scalar('d'),
                Node::scalar('e'),
            ]),
            "- a\n- b\n- - c\n- d\n- e\n",
        ];

        yield 'nested mapping' => [
            Node::mapping([Node::scalar('a'), self::map(['b', '1'])]),
            "a:\n  b: 1\n",
        ];

        yield 'sequence under key is indented' => [
            Node::mapping([Node::scalar('a'), Node::sequence([Node::scalar('1'), Node::scalar('2')])]),
            "a:\n  - 1\n  - 2\n",
        ];

        yield 'mapping inside sequence' => [
            Node::sequence([self::map(['a', '1'], ['b', '2'])]),
            "- a: 1\n  b: 2\n",
        ];

        yield 'sequence inside sequence' => [
            Node::sequence([Node::sequence([Node::scalar('1'), Node::scalar('2')])]),
            "- - 1\n  - 2\n",
        ];

        yield 'empty collections' => [
            Node::mapping([
                Node::scalar('a'), Node::sequence(),
                Node::scalar('b'), Node::mapping(),
            ]),
            "a: []\nb: {}\n",
        ];

        yield 'flow collections' => [
            Node::mapping([
                Node::scalar('a'), Node::sequence([Node::scalar('1'), Node::scalar('2')], NodeStyleEnum::Flow),
                Node::scalar('b'), Node::mapping([Node::scalar('c'), Node::scalar('3'), Node::scalar('d'), Node::scalar('4')], NodeStyleEnum::Flow),
            ]),
            "a: [1, 2]\nb: {c: 3, d: 4}\n",
        ];

        yield 'block collection inside flow collection becomes flow' => [
            Node::sequence([Node::sequence([Node::scalar('1')]), Node::mapping([Node::scalar('a'), Node::scalar('b')])], NodeStyleEnum::Flow),
            "[[1], {a: b}]\n",
        ];

        yield 'null renders empty' => [
            Node::mapping([Node::scalar('a'), Node::scalar('')]),
            "a:\n",
        ];

        yield 'null word is kept' => [
            Node::mapping([Node::scalar('a'), Node::scalar('null')]),
            "a: null\n",
        ];

        yield 'empty null in sequence' => [Node::sequence([Node::scalar('')]), "-\n"];

        yield 'empty null in flow is quoted' => [
            Node::sequence([Node::scalar('')], NodeStyleEnum::Flow),
            "['']\n",
        ];

        yield 'string that looks like an int is double quoted' => [
            Node::mapping([Node::scalar('a'), Node::scalar('123', '!!str')]),
            "a: \"123\"\n",
        ];

        yield 'string that looks like bool and null is double quoted' => [
            Node::sequence([Node::scalar('true', '!!str'), Node::scalar('null', '!!str'), Node::scalar('~', '!!str')]),
            "- \"true\"\n- \"null\"\n- \"~\"\n",
        ];

        yield 'string that looks like a float is double quoted' => [
            Node::sequence([Node::scalar('1.5', '!!str'), Node::scalar('.inf', '!!str')]),
            "- \"1.5\"\n- \".inf\"\n",
        ];

        yield 'string that looks like a timestamp is double quoted' => [
            Node::sequence([Node::scalar('2001-12-14', '!!str')]),
            "- \"2001-12-14\"\n",
        ];

        yield 'timestamp node stays plain' => [
            Node::sequence([Node::scalar('2001-12-14T21:59:43.10-05:00', '!!timestamp')]),
            "- 2001-12-14T21:59:43.10-05:00\n",
        ];

        yield 'plain string stays plain' => [
            Node::sequence([Node::scalar('hello world', '!!str'), Node::scalar('yes', '!!str')]),
            "- hello world\n- yes\n",
        ];

        yield 'empty string key is single quoted' => [
            Node::mapping([Node::scalar(''), Node::scalar('x')]),
            "'': x\n",
        ];

        yield 'explicitly double quoted empty key stays double quoted' => [
            Node::mapping([Node::scalar('', '!!str', NodeStyleEnum::DoubleQuoted), Node::scalar('x')]),
            "\"\": x\n",
        ];

        yield 'empty string value keeps its double quotes' => [
            Node::mapping([Node::scalar('a'), Node::scalar('', '!!str', NodeStyleEnum::DoubleQuoted)]),
            "a: \"\"\n",
        ];

        yield 'leading and trailing space force single quotes' => [
            Node::sequence([Node::scalar(' a', '!!str'), Node::scalar('a ', '!!str')]),
            "- ' a'\n- 'a '\n",
        ];

        yield 'indicators force single quotes' => [
            Node::sequence([
                Node::scalar('a: b', '!!str'),
                Node::scalar('#x', '!!str'),
                Node::scalar('a #x', '!!str'),
                Node::scalar('- x', '!!str'),
                Node::scalar('*x', '!!str'),
                Node::scalar('&x', '!!str'),
                Node::scalar('!x', '!!str'),
                Node::scalar('[x', '!!str'),
                Node::scalar('%x', '!!str'),
                Node::scalar('---', '!!str'),
                Node::scalar('@x', '!!str'),
            ]),
            "- 'a: b'\n- '#x'\n- 'a #x'\n- '- x'\n- '*x'\n- '&x'\n- '!x'\n- '[x'\n- '%x'\n- '---'\n- '@x'\n",
        ];

        yield 'harmless punctuation stays plain' => [
            Node::sequence([Node::scalar('a:b', '!!str'), Node::scalar('a#b', '!!str'), Node::scalar('-x', '!!str'), Node::scalar('a,b', '!!str'), Node::scalar('http://x.y/z?q=1', '!!str')]),
            "- a:b\n- a#b\n- -x\n- a,b\n- http://x.y/z?q=1\n",
        ];

        yield 'flow context quotes commas and brackets' => [
            Node::sequence([Node::scalar('a,b', '!!str'), Node::scalar('a]', '!!str'), Node::scalar('a:b', '!!str')], NodeStyleEnum::Flow),
            "['a,b', 'a]', 'a:b']\n",
        ];

        yield 'single quote is doubled' => [
            Node::sequence([Node::scalar("it's", '!!str', NodeStyleEnum::SingleQuoted)]),
            "- 'it''s'\n",
        ];

        yield 'preserved single quoted style' => [
            Node::sequence([Node::scalar('abc', '!!str', NodeStyleEnum::SingleQuoted)]),
            "- 'abc'\n",
        ];

        yield 'preserved double quoted style' => [
            Node::sequence([Node::scalar('abc', '!!str', NodeStyleEnum::DoubleQuoted)]),
            "- \"abc\"\n",
        ];

        yield 'tab forces double quotes with escape' => [
            Node::sequence([Node::scalar("a\tb", '!!str')]),
            "- \"a\\tb\"\n",
        ];

        yield 'double quoted escapes' => [
            Node::sequence([Node::scalar("q\"b\\\x01\x7f", '!!str', NodeStyleEnum::DoubleQuoted)]),
            "- \"q\\\"b\\\\\\x01\\x7F\"\n",
        ];

        yield 'carriage return is escaped' => [
            Node::sequence([Node::scalar("a\r\nb", '!!str')]),
            "- \"a\\r\\nb\"\n",
        ];

        yield 'astral characters are escaped like go-yaml' => [
            Node::sequence([Node::scalar("smile \u{1F600}", '!!str')]),
            "- \"smile \\U0001F600\"\n",
        ];

        yield 'bmp unicode stays literal' => [
            Node::sequence([Node::scalar('caf' . "\u{e9}" . ' ' . "\u{4e2d}\u{6587}", '!!str')]),
            "- caf\u{e9} \u{4e2d}\u{6587}\n",
        ];

        yield 'multi-line string uses literal style with strip' => [
            Node::mapping([Node::scalar('a'), Node::scalar("x\ny", '!!str')]),
            "a: |-\n  x\n  y\n",
        ];

        yield 'multi-line string with trailing newline uses clip' => [
            Node::mapping([Node::scalar('a'), Node::scalar("x\ny\n", '!!str')]),
            "a: |\n  x\n  y\n",
        ];

        yield 'multi-line string with several trailing newlines uses keep' => [
            Node::mapping([Node::scalar('a'), Node::scalar("x\n\n", '!!str')]),
            "a: |+\n  x\n\n",
        ];

        yield 'literal with leading space gets an indentation hint' => [
            Node::mapping([Node::scalar('a'), Node::scalar(" x\ny", '!!str', NodeStyleEnum::Literal)]),
            "a: |2-\n   x\n  y\n",
        ];

        yield 'literal blank line inside' => [
            Node::mapping([Node::scalar('a'), Node::scalar("x\n\ny", '!!str', NodeStyleEnum::Literal)]),
            "a: |-\n  x\n\n  y\n",
        ];

        yield 'literal in a sequence in a mapping' => [
            Node::mapping([Node::scalar('a'), Node::sequence([Node::scalar("x\ny", '!!str')])]),
            "a:\n  - |-\n    x\n    y\n",
        ];

        yield 'literal with trailing space falls back to double quotes' => [
            Node::mapping([Node::scalar('a'), Node::scalar("x\ny ", '!!str', NodeStyleEnum::Literal)]),
            "a: \"x\\ny \"\n",
        ];

        yield 'literal in flow becomes double quoted' => [
            Node::sequence([Node::scalar("x\ny", '!!str')], NodeStyleEnum::Flow),
            "[\"x\\ny\"]\n",
        ];

        yield 'folded style is preserved' => [
            Node::mapping([Node::scalar('a'), Node::scalar("x\ny\n", '!!str', NodeStyleEnum::Folded)]),
            "a: >\n  x\n\n  y\n\n",
        ];

        yield 'folded scalar whose value starts with a space gets no extra break' => [
            Node::mapping([Node::scalar('a'), Node::scalar(" x\n", '!!str', NodeStyleEnum::Folded)]),
            "a: >2\n   x\n",
        ];

        yield 'multi-line key becomes a complex key' => [
            Node::mapping([Node::scalar("a\nb", '!!str'), Node::scalar('v')]),
            "? |-\n  a\n  b\n: v\n",
        ];

        yield 'single quoted multi-line folds with a blank line' => [
            Node::mapping([Node::scalar('a'), Node::scalar("x\ny", '!!str', NodeStyleEnum::SingleQuoted)]),
            "a: 'x\n\n  y'\n",
        ];

        yield 'anchor on a scalar and alias' => [
            Node::mapping([
                Node::scalar('a'), self::anchored(Node::scalar('1'), 'x'),
                Node::scalar('b'), Node::alias('x', Node::scalar('1')),
            ]),
            "a: &x 1\nb: *x\n",
        ];

        yield 'anchor on a mapping' => [
            Node::mapping([
                Node::scalar('a'), self::anchored(self::map(['b', '1']), 'x'),
                Node::scalar('c'), Node::alias('x', Node::scalar('1')),
            ]),
            "a: &x\n  b: 1\nc: *x\n",
        ];

        yield 'merge key with alias' => [
            Node::mapping([Node::scalar('<<'), Node::alias('x', Node::scalar('1')), Node::scalar('k'), Node::scalar('v')]),
            "<<: *x\nk: v\n",
        ];

        yield 'alias as key gets a trailing space' => [
            Node::mapping([Node::alias('x', Node::scalar('1')), Node::scalar('v')]),
            "*x : v\n",
        ];

        yield 'custom tag on a scalar' => [
            Node::mapping([Node::scalar('a'), self::tagged(Node::scalar('bar', '!foo'))]),
            "a: !foo bar\n",
        ];

        yield 'explicit str tag is kept' => [
            Node::mapping([Node::scalar('a'), self::tagged(Node::scalar('12', '!!str'))]),
            "a: !!str 12\n",
        ];

        yield 'binary tag' => [
            Node::mapping([Node::scalar('a'), Node::scalar('aGVsbG8=', '!!binary')]),
            "a: !!binary aGVsbG8=\n",
        ];

        yield 'tag mismatching the plain value is kept' => [
            Node::mapping([Node::scalar('a'), Node::scalar('abc', '!!int')]),
            "a: !!int abc\n",
        ];

        yield 'custom tag on a mapping' => [
            Node::mapping([Node::scalar('a'), new Node(NodeKindEnum::Mapping, '!thing', NodeStyleEnum::Default, '', [Node::scalar('b'), Node::scalar('1')])]),
            "a: !thing\n  b: 1\n",
        ];

        yield 'custom tag on a flow sequence' => [
            Node::mapping([Node::scalar('a'), new Node(NodeKindEnum::Sequence, '!thing', NodeStyleEnum::Flow, '', [Node::scalar('1')])]),
            "a: !thing [1]\n",
        ];

        yield 'complex key sequence' => [
            Node::mapping([Node::sequence([Node::scalar('a'), Node::scalar('b')]), Node::scalar('v')]),
            "? - a\n  - b\n: v\n",
        ];

        yield 'long key becomes complex' => [
            Node::mapping([Node::scalar(str_repeat('k', 130)), Node::scalar('v')]),
            '? ' . str_repeat('k', 130) . "\n: v\n",
        ];

        yield 'invalid utf8 becomes binary' => [
            Node::mapping([Node::scalar('a'), Node::scalar("\xff\xfe", '!!str')]),
            "a: !!binary //4=\n",
        ];
    }

    public function testHeadCommentAfterAFootCommentIsSeparatedByABlankLine(): void
    {
        $first               = Node::scalar('a');
        $first->headComment  = '# a1';
        $first->footComment  = '# a2';

        $second              = Node::scalar('b');
        $second->headComment = '# b1';

        $node                = Node::mapping([$first, Node::scalar('1'), $second, Node::scalar('2')]);

        self::assertSame("# a1\na: 1\n# a2\n\n# b1\nb: 2\n", new YamlEmitter()->emit($node));
    }

    public function testACyclicStructureIsRefusedInsteadOfExhaustingMemory(): void
    {
        $inner            = Node::mapping([Node::scalar('b'), Node::scalar('1')]);
        $root             = Node::mapping([Node::scalar('a'), $inner]);
        $inner->content[] = Node::scalar('c');
        $inner->content[] = $root;

        try {
            new YamlEmitter()->emit($root);
            self::fail('a cyclic structure must be refused');
        } catch (LogicException $logicException) {
            self::assertStringContainsString('cyclic', $logicException->getMessage());
        }
    }

    public function testADocumentHoldingItselfIsRefused(): void
    {
        $document          = new Node(NodeKindEnum::Document);
        $document->content = [$document];

        $this->expectException(LogicException::class);

        new YamlEmitter()->emit($document);
    }

    public function testASharedNodeThatIsNotACycleStillEmitsTwice(): void
    {
        $shared = Node::mapping([Node::scalar('x'), Node::scalar('1')]);
        $root   = Node::mapping([Node::scalar('a'), $shared, Node::scalar('b'), $shared]);

        self::assertSame("a:\n  x: 1\nb:\n  x: 1\n", new YamlEmitter()->emit($root));
    }

    public function testHeadCommentAfterAKeyWithoutFootCommentFollowsDirectly(): void
    {
        $second              = Node::scalar('b');
        $second->headComment = '# b1';

        $node                = Node::mapping([Node::scalar('a'), Node::scalar('1'), $second, Node::scalar('2')]);

        self::assertSame("a: 1\n# b1\nb: 2\n", new YamlEmitter()->emit($node));
    }

    public function testIndentOptionInSequenceUnderMapping(): void
    {
        $node = Node::mapping([Node::scalar('a'), Node::sequence([Node::scalar('1')])]);

        self::assertSame("a:\n    - 1\n", new YamlEmitter()->emit($node, new EmitOptions(indent: 4)));
    }

    public function testIndentOptionPadsMappingInSequence(): void
    {
        $node = Node::sequence([self::map(['a', '1'], ['b', '2'])]);

        self::assertSame("- a: 1\n  b: 2\n", new YamlEmitter()->emit($node, new EmitOptions(indent: 4)));
    }

    public function testIndentRoundsNestedLevelsUpToAMultipleOfTheStep(): void
    {
        $inner = Node::mapping([Node::scalar('m'), Node::scalar('1'), Node::scalar('n'), Node::sequence([Node::scalar('o')])]);
        $node  = Node::sequence([Node::mapping([Node::scalar('k'), Node::sequence([$inner])])]);

        self::assertSame("- k:\n   - m: 1\n     n:\n      - o\n", new YamlEmitter()->emit($node, new EmitOptions(indent: 3)));
    }

    public function testIndentOutsideTwoToNineFallsBackToTwo(): void
    {
        $node = Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar('b'), Node::scalar('1')])]);

        self::assertSame("a:\n  b: 1\n", new YamlEmitter()->emit($node, new EmitOptions(indent: 0)));
    }

    public function testIndentOptionNestedMappings(): void
    {
        $node = Node::mapping([Node::scalar('a'), Node::mapping([Node::scalar('b'), self::map(['c', '1'])])]);

        self::assertSame("a:\n   b:\n      c: 1\n", new YamlEmitter()->emit($node, new EmitOptions(indent: 3)));
    }

    public function testCommentsHeadLineAndFoot(): void
    {
        $key                = Node::scalar('a');
        $key->headComment   = '# head';

        $value              = Node::scalar('1');
        $value->lineComment = '# line';

        $key2               = Node::scalar('b');
        $key2->footComment  = '# foot';

        $node               = Node::mapping([$key, $value, $key2, Node::scalar('2')]);

        self::assertSame("# head\na: 1 # line\nb: 2\n# foot\n", new YamlEmitter()->emit($node));
    }

    public function testMultiLineCommentsAndBlankLinesAreVerbatim(): void
    {
        $key              = Node::scalar('a');
        $key->headComment = "# one\n\n# two";

        self::assertSame("# one\n\n# two\na: 1\n", new YamlEmitter()->emit(Node::mapping([$key, Node::scalar('1')])));
    }

    public function testCommentWithoutHashGetsOne(): void
    {
        $key              = Node::scalar('a');
        $key->headComment = 'plain';

        self::assertSame("# plain\na: 1\n", new YamlEmitter()->emit(Node::mapping([$key, Node::scalar('1')])));
    }

    public function testCommentsAreIndentedWithTheirCollection(): void
    {
        $inner                  = Node::scalar('b');
        $inner->headComment     = '# inner head';

        $innerFoot              = Node::scalar('c');
        $innerFoot->footComment = '# inner foot';

        $outer                  = Node::mapping([Node::scalar('a'), Node::mapping([$inner, Node::scalar('1'), $innerFoot, Node::scalar('2')])]);

        self::assertSame("a:\n  # inner head\n  b: 1\n  c: 2\n  # inner foot\n", new YamlEmitter()->emit($outer));
    }

    public function testLineCommentOnKeyOfBlockCollection(): void
    {
        $key              = Node::scalar('a');
        $key->lineComment = '# note';

        $node             = Node::mapping([$key, self::map(['b', '1'])]);

        self::assertSame("a: # note\n  b: 1\n", new YamlEmitter()->emit($node));
    }

    public function testSequenceItemComments(): void
    {
        $one              = Node::scalar('1');
        $one->headComment = '# first';
        $one->lineComment = '# l';

        $two              = Node::scalar('2');
        $two->footComment = '# end';

        self::assertSame("# first\n- 1 # l\n- 2\n# end\n", new YamlEmitter()->emit(Node::sequence([$one, $two])));
    }

    public function testLineCommentAfterFlowCollection(): void
    {
        $seq              = Node::sequence([Node::scalar('1')], NodeStyleEnum::Flow);
        $seq->lineComment = '# c';

        self::assertSame("a: [1] # c\n", new YamlEmitter()->emit(Node::mapping([Node::scalar('a'), $seq])));
    }

    public function testUnwrapScalar(): void
    {
        $emitter = new YamlEmitter();

        self::assertSame("hello\n", $emitter->emit(Node::scalar('hello', '!!str', NodeStyleEnum::DoubleQuoted)));
        self::assertSame("a\nb\n", $emitter->emit(Node::scalar("a\nb", '!!str')));
        self::assertSame("\"hello\"\n", $emitter->emit(Node::scalar('hello', '!!str', NodeStyleEnum::DoubleQuoted), new EmitOptions(unwrapScalar: false)));
        self::assertSame("|-\n  a\n  b\n", $emitter->emit(Node::scalar("a\nb", '!!str'), new EmitOptions(unwrapScalar: false)));
        self::assertSame("hello\n", $emitter->emit(Node::document(Node::scalar('hello'))));
    }

    public function testBareAliasAndScalarRoots(): void
    {
        $emitter = new YamlEmitter();

        self::assertSame("*x\n", $emitter->emit(Node::alias('x', Node::scalar('1'))));
        self::assertSame("- 1\n", $emitter->emit(Node::sequence([Node::scalar('1')])));
    }

    public function testEmptyCollectionRoots(): void
    {
        $emitter = new YamlEmitter();

        self::assertSame("[]\n", $emitter->emit(Node::sequence()));
        self::assertSame("{}\n", $emitter->emit(Node::mapping()));
    }

    public function testDocumentMarkersAndDirectives(): void
    {
        $emitter = new YamlEmitter();
        $doc     = Node::document(self::map(['a', '1']));

        self::assertSame("a: 1\n", $emitter->emit($doc));

        $doc->explicitStart = true;
        self::assertSame("---\na: 1\n", $emitter->emit($doc));

        $doc->explicitEnd = true;
        self::assertSame("---\na: 1\n...\n", $emitter->emit($doc));

        $doc->directives = '%YAML 1.1';
        self::assertSame("%YAML 1.1\n---\na: 1\n...\n", $emitter->emit($doc));
    }

    public function testDirectiveForcesSeparatorEvenWithoutExplicitStart(): void
    {
        $doc             = Node::document(self::map(['a', '1']));
        $doc->directives = "%YAML 1.1\n";

        self::assertSame("%YAML 1.1\n---\na: 1\n", new YamlEmitter()->emit($doc));
    }

    public function testDocumentHeadCommentBeforeExplicitSeparator(): void
    {
        $doc                = Node::document(self::map(['a', '1']));
        $doc->headComment   = "# hi\n# there";
        $doc->explicitStart = true;

        self::assertSame("# hi\n# there\n---\na: 1\n", new YamlEmitter()->emit($doc));

        $doc->explicitStart = false;
        self::assertSame("# hi\n# there\na: 1\n", new YamlEmitter()->emit($doc));
    }

    public function testDocumentHeadCommentEndingInNewlineIsFollowedByABlankLine(): void
    {
        $doc                = Node::document(self::map(['a', '1']));
        $doc->headComment   = "# hi\n";
        $doc->explicitStart = true;

        self::assertSame("# hi\n\n---\na: 1\n", new YamlEmitter()->emit($doc));

        $doc->headComment   = "# hi\n\n";
        $doc->explicitStart = false;
        self::assertSame("# hi\n\n\na: 1\n", new YamlEmitter()->emit($doc));
    }

    public function testDocumentFootComment(): void
    {
        $doc              = Node::document(self::map(['a', '1']));
        $doc->footComment = '# bye';

        self::assertSame("a: 1\n# bye\n", new YamlEmitter()->emit($doc));
    }

    public function testCommentOnlyDocument(): void
    {
        $root              = Node::scalar('', '!!null');
        $root->headComment = "# only\n# comments";

        $doc               = Node::document($root);

        // A bare scalar prints as its value alone, as the reference does: its comments are not printed.
        self::assertSame("\n", new YamlEmitter()->emit($doc));
    }

    public function testNoDocSeparatorSuppressesExplicitMarkers(): void
    {
        $a                = Node::document(self::map(['a', '1']));
        $a->explicitStart = true;

        self::assertSame("a: 1\n", new YamlEmitter()->emit($a, new EmitOptions(noDocSeparator: true)));
    }

    public function testPrettyPrintNormalisesStyles(): void
    {
        $node = Node::mapping([
            Node::scalar('k', '!!str', NodeStyleEnum::DoubleQuoted),
            Node::sequence([
                Node::scalar('yes', '!!str', NodeStyleEnum::DoubleQuoted),
                Node::scalar('yesSir', '!!str', NodeStyleEnum::DoubleQuoted),
                Node::scalar('true', '!!str', NodeStyleEnum::DoubleQuoted),
                Node::scalar('Y', '!!str', NodeStyleEnum::SingleQuoted),
                Node::scalar('yes', '!!str'),
                Node::scalar("a\nb", '!!str', NodeStyleEnum::Literal),
            ], NodeStyleEnum::Flow),
            Node::scalar('m'),
            Node::mapping([Node::scalar('x'), Node::scalar('1')], NodeStyleEnum::Flow),
        ]);

        self::assertSame(
            "k:\n  - \"yes\"\n  - yesSir\n  - \"true\"\n  - \"Y\"\n  - yes\n  - |-\n    a\n    b\nm:\n  x: 1\n",
            new YamlEmitter()->emit($node, new EmitOptions(prettyPrint: true)),
        );
    }

    public function testPrettyPrintKeepsComments(): void
    {
        $key              = Node::scalar('a', '!!str', NodeStyleEnum::DoubleQuoted);
        $key->headComment = '# c';

        self::assertSame("# c\na: b\n", new YamlEmitter()->emit(Node::mapping([$key, Node::scalar('b', '!!str', NodeStyleEnum::SingleQuoted)]), new EmitOptions(prettyPrint: true)));
    }

    public function testFootCommentIsFollowedByBlankLineOnlyBeforeTheNextEntryAtItsIndent(): void
    {
        $a              = Node::scalar('a');
        $a->footComment = '# foot';

        $out = new YamlEmitter()->emit(Node::mapping([
            $a, Node::scalar('1'),
            Node::scalar('b'), Node::scalar('2'),
            Node::scalar('c'), Node::scalar('3'),
        ]), new EmitOptions());

        self::assertSame("a: 1\n# foot\n\nb: 2\nc: 3\n", $out);
    }

    public function testColours(): void
    {
        $key              = Node::scalar('a');
        $key->footComment = '# f';

        $node             = Node::mapping([
            $key, Node::scalar('str', '!!str'),
            Node::scalar('b'), Node::scalar('1'),
            Node::scalar('c'), Node::scalar('true'),
            Node::scalar('d'), Node::sequence([Node::scalar('x')]),
        ]);
        $esc = "\x1b[";

        self::assertSame(
            $esc . '36ma' . $esc . '0m: ' . $esc . '32mstr' . $esc . "0m\n"
            . $esc . '90m# f' . $esc . "0m\n\n"
            . $esc . '36mb' . $esc . '0m: ' . $esc . '95m1' . $esc . "0m\n"
            . $esc . '36mc' . $esc . '0m: ' . $esc . '95mtrue' . $esc . "0m\n"
            . $esc . '36md' . $esc . "0m:\n  - " . $esc . '32mx' . $esc . "0m\n",
            new YamlEmitter()->emit($node, new EmitOptions(colors: true)),
        );
    }

    #[DataProvider('scalarCases')]
    public function testEmitsScalarsLikeGoYaml(Node $node, EmitOptions $options, string $expected): void
    {
        self::assertSame($expected, new YamlEmitter()->emit($node, $options));
    }

    /**
     * @return iterable<string, array{Node, EmitOptions, string}>
     */
    public static function scalarCases(): iterable
    {
        $esc    = "\x1b[";
        $plain  = new EmitOptions();
        $colour = new EmitOptions(colors: true);
        $under  = static fn (string $value, string $tag): Node => self::kv(Node::scalar($value, $tag));
        $folded = static fn (string $value): Node => self::kv(Node::scalar($value, '!!str', NodeStyleEnum::Folded));
        $block  = static fn (string $value): Node => self::kv(Node::scalar($value, '!!str', NodeStyleEnum::Literal));

        // Folded scalars: go-yaml adds a blank line between lines of text unless the value starts with a blank.
        yield 'folded two lines' => [$folded("a\nb"), $plain, "k: >-\n  a\n\n  b\n"];

        yield 'folded blank line' => [$folded("a\n\nb"), $plain, "k: >-\n  a\n\n\n  b\n"];

        yield 'folded indented line' => [$folded("a\n b\nc"), $plain, "k: >-\n  a\n\n   b\n  c\n"];

        yield 'folded starting with a space' => [$folded(" a\nb"), $plain, "k: >2-\n   a\n  b\n"];

        yield 'folded three lines' => [$folded("a\nb\nc"), $plain, "k: >-\n  a\n\n  b\n\n  c\n"];

        yield 'folded keeps blank runs' => [$folded("a\n\n\nb\n"), $plain, "k: >\n  a\n\n\n\n  b\n\n"];

        yield 'folded leading break' => [$folded("\na"), $plain, "k: >2-\n\n  a\n"];

        yield 'folded more indented then blank' => [$folded("a\n  b\n\nc"), $plain, "k: >-\n  a\n\n    b\n\n  c\n"];

        yield 'folded starting with a space and ending in a break' => [$folded(" a\nb\n"), $plain, "k: >2\n   a\n  b\n"];

        // Literal scalars and the indentation indicator.
        yield 'literal starting with a space' => [$block(" a\nb"), $plain, "k: |2-\n   a\n  b\n"];

        yield 'literal starting with a break' => [$block("\na\n"), $plain, "k: |2\n\n  a\n"];

        yield 'literal keeping trailing breaks' => [$block("a\n\n"), $plain, "k: |+\n  a\n\n"];

        yield 'literal indent indicator follows the indent option' => [$block(" a\nb"), new EmitOptions(indent: 4), "k: |4-\n     a\n    b\n"];

        yield 'literal in a sequence' => [Node::sequence([Node::scalar(" a\nb", '!!str', NodeStyleEnum::Literal), Node::scalar('x')]), $plain, "- |2-\n   a\n  b\n- x\n"];

        yield 'root literal' => [Node::scalar(" a\nb", '!!str', NodeStyleEnum::Literal), new EmitOptions(unwrapScalar: false), "|2-\n   a\n  b\n"];

        // Invalid UTF-8 becomes base64 wrapped at 70 characters.
        yield 'binary of 100 bytes' => [
            self::kv(Node::scalar(str_repeat("\xFF", 100))),
            $plain,
            "k: !!binary |-\n  " . str_repeat('/', 70) . "\n  " . str_repeat('/', 63) . "w==\n",
        ];

        yield 'binary of 52 bytes' => [
            self::kv(Node::scalar(str_repeat("\xFF", 52))),
            $plain,
            "k: !!binary |-\n  " . str_repeat('/', 69) . "w\n  ==\n",
        ];

        yield 'binary of 53 bytes' => [
            self::kv(Node::scalar(str_repeat("\xFF", 53))),
            $plain,
            "k: !!binary |-\n  " . str_repeat('/', 70) . "\n  8=\n",
        ];

        // Strings that would read back as numbers once underscores are ignored are quoted.
        yield 'string 1_000' => [$under('1_000', '!!str'), $plain, "k: \"1_000\"\n"];

        yield 'string 1_0.5' => [$under('1_0.5', '!!str'), $plain, "k: \"1_0.5\"\n"];

        yield 'string -1_0' => [$under('-1_0', '!!str'), $plain, "k: \"-1_0\"\n"];

        yield 'string +1_0' => [$under('+1_0', '!!str'), $plain, "k: \"+1_0\"\n"];

        yield 'string .5_0' => [$under('.5_0', '!!str'), $plain, "k: \".5_0\"\n"];

        yield 'string a_b' => [$under('a_b', '!!str'), $plain, "k: a_b\n"];

        yield 'string 1_a' => [$under('1_a', '!!str'), $plain, "k: 1_a\n"];

        yield 'string _1' => [$under('_1', '!!str'), $plain, "k: _1\n"];

        yield 'int 1_000' => [$under('1_000', '!!int'), $plain, "k: 1_000\n"];

        // Escapes in double quotes.
        yield 'control character' => [$under("a\x01b", '!!str'), $plain, "k: \"a\\x01b\"\n"];

        yield 'next line' => [$under("a\u{85}b", '!!str'), $plain, "k: \"a\\Nb\"\n"];

        yield 'noncharacter in the BMP' => [$under("a\u{FFFF}b", '!!str'), $plain, "k: \"a\\uFFFFb\"\n"];

        yield 'byte order mark' => [$under("a\u{FEFF}b", '!!str'), $plain, "k: \"a\\uFEFFb\"\n"];

        yield 'astral character' => [$under("a\u{1F600}b", '!!str'), $plain, "k: \"a\\U0001F600b\"\n"];

        yield 'printable beyond latin 1 beside a tab' => [$under("a\u{100}\t", '!!str'), $plain, "k: \"a\u{100}\\t\"\n"];

        // Colours follow the explicit tag, or the implicit one when there is none.
        yield 'coloured explicit float' => [$under('1.5', '!!float'), $colour, $esc . '36mk' . $esc . '0m: ' . $esc . '95m1.5' . $esc . "0m\n"];

        yield 'coloured implicit float' => [$under('1.5', ''), $colour, $esc . '36mk' . $esc . '0m: ' . $esc . '95m1.5' . $esc . "0m\n"];

        yield 'coloured explicit bool' => [$under('true', '!!bool'), $colour, $esc . '36mk' . $esc . '0m: ' . $esc . '95mtrue' . $esc . "0m\n"];

        yield 'colour follows an explicit tag over the text' => [$under('abc', '!!int'), $colour, $esc . '36mk' . $esc . '0m: !!int ' . $esc . '95mabc' . $esc . "0m\n"];

        yield 'explicit null has no colour' => [$under('~', '!!null'), $colour, $esc . '36mk' . $esc . "0m: ~\n"];

        yield 'implicit null has no colour' => [$under('null', ''), $colour, $esc . '36mk' . $esc . "0m: null\n"];

        yield 'quoted string that looks like a float' => [
            $under('1.5', '!!str'),
            $colour,
            $esc . '36mk' . $esc . '0m: ' . $esc . '32m"' . $esc . '0m' . $esc . '32m1.5' . $esc . '0m' . $esc . '32m"' . $esc . "0m\n",
        ];

        // Keys that are not simple use the explicit `?` form.
        yield 'mapping as key' => [Node::mapping([self::kv(Node::scalar('1'), 'a'), Node::scalar('v')]), $plain, "? a: 1\n: v\n"];

        yield 'sequence as key with mapping value' => [Node::mapping([Node::sequence([Node::scalar('a')]), self::kv(Node::scalar('c'), 'b')]), $plain, "? - a\n: b: c\n"];

        yield 'sequence as key with sequence value' => [Node::mapping([Node::sequence([Node::scalar('a')]), Node::sequence([Node::scalar('b')])]), $plain, "? - a\n: - b\n"];

        yield 'multiline key' => [Node::mapping([Node::scalar("a\nb"), Node::scalar('v')]), $plain, "? |-\n  a\n  b\n: v\n"];

        yield 'multiline key and value' => [Node::mapping([Node::scalar("a\nb"), Node::scalar("x\ny")]), $plain, "? |-\n  a\n  b\n: |-\n  x\n  y\n"];

        yield 'complex key followed by a simple one' => [
            Node::mapping([Node::sequence([Node::scalar('a')]), Node::scalar('v'), Node::scalar('z'), Node::scalar('w')]),
            $plain,
            "? - a\n: v\nz: w\n",
        ];

        yield 'complex key with a wider indent' => [
            Node::mapping([Node::sequence([Node::scalar('a')]), Node::scalar('v'), Node::scalar('z'), Node::scalar('w')]),
            new EmitOptions(indent: 4),
            "?   - a\n: v\nz: w\n",
        ];
    }

    public function testRootHeadCommentOfAScalarComesFirst(): void
    {
        $root              = Node::scalar('a');
        $root->headComment = '# top';

        self::assertSame("# top\na\n", new YamlEmitter()->emit($root, new EmitOptions(unwrapScalar: false)));
    }

    public function testCycleThroughAliasesTerminates(): void
    {
        $anchor            = self::map(['a', '1']);
        $anchor->anchor    = 'x';
        $anchor->content[] = Node::scalar('self');
        $anchor->content[] = Node::alias('x', $anchor);

        self::assertSame("&x\na: 1\nself: *x\n", new YamlEmitter()->emit($anchor));
    }

    public function testDoesNotMutateInput(): void
    {
        $node = Node::mapping([Node::scalar('a'), Node::scalar("x\ny", '!!str')]);
        $copy = $node->deepCopy();

        new YamlEmitter()->emit($node, new EmitOptions(prettyPrint: true));

        self::assertEquals($copy, $node);
    }

    /**
     * @param array{string, string} ...$pairs
     */
    private static function map(array ...$pairs): Node
    {
        $content = [];
        foreach ($pairs as [$key, $value]) {
            $content[] = Node::scalar($key);
            $content[] = Node::scalar($value);
        }

        return Node::mapping($content);
    }

    private static function kv(Node $value, string $key = 'k'): Node
    {
        return Node::mapping([Node::scalar($key), $value]);
    }

    private static function anchored(Node $node, string $anchor): Node
    {
        $node->anchor = $anchor;

        return $node;
    }

    private static function commented(Node $node, string $head): Node
    {
        $node->headComment = '# ' . $head;

        return $node;
    }

    private static function tagged(Node $node): Node
    {
        $node->tagExplicit = true;

        return $node;
    }
}
