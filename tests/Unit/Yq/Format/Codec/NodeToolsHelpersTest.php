<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\NodeTools;
use LTS\PhpXq\Yq\Format\FormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NodeToolsHelpersTest extends TestCase
{
    public function testUnwrapFollowsAChainOfSixtyThreeAliases(): void
    {
        $target = Node::scalar('end');
        $node   = $target;
        for ($i = 0; $i < 63; ++$i) {
            $node = Node::alias('a' . $i, $node);
        }

        self::assertSame($target, NodeTools::unwrap($node));
    }

    public function testUnwrapRejectsAChainOfSixtyFourAliases(): void
    {
        $node = Node::scalar('end');
        for ($i = 0; $i < 64; ++$i) {
            $node = Node::alias('a' . $i, $node);
        }

        try {
            NodeTools::unwrap($node);
        } catch (FormatException $formatException) {
            self::assertSame('alias chain is too deep', $formatException->getMessage());

            return;
        }

        self::fail('expected a FormatException');
    }

    public function testUnwrapFollowsDocumentsInsideAliases(): void
    {
        $target = Node::scalar('x');

        self::assertSame($target, NodeTools::unwrap(Node::alias('a', Node::document($target))));
    }

    public function testMergeKeyNeedsADefaultStyleStringOrMergeTag(): void
    {
        self::assertTrue(NodeTools::isMergeKey(Node::scalar('<<', '!!merge')));
        self::assertTrue(NodeTools::isMergeKey(Node::scalar('<<', '!!str')));
        self::assertFalse(NodeTools::isMergeKey(Node::scalar('<<', '!!int')));
        self::assertFalse(NodeTools::isMergeKey(Node::scalar('<<', '!!str', NodeStyleEnum::SingleQuoted)));
        self::assertFalse(NodeTools::isMergeKey(Node::sequence()));
    }

    public function testMergeKeyInThirdPositionIsExpanded(): void
    {
        $root = $this->parse("base: &b {x: 1}\nm:\n  a: 0\n  b: 0\n  <<: *b\n");
        $map  = $root->content[3];

        $values = array_map(static fn (Node $node): string => $node->value, NodeTools::flatContent($map));
        self::assertSame(['a', '0', 'b', '0', 'x', '1'], $values);

        $pairs = [];
        foreach (NodeTools::pairs($map) as [$key, $value]) {
            $pairs[] = $key->value . '=' . $value->value;
        }

        self::assertSame(['a=0', 'b=0', 'x=1'], $pairs);
    }

    public function testMergedKeysDoNotRepeatAnExplicitKeyEvenWhenAnotherSourceHasIt(): void
    {
        $root = $this->parse("x: &x {a: 1, b: 2}\ny: &y {b: 3, c: 4}\nm:\n  <<: [*x, *y]\n  c: 5\n");
        $map  = $root->content[5];

        $pairs = [];
        foreach (NodeTools::pairs($map) as [$key, $value]) {
            $pairs[] = $key->value . '=' . $value->value;
        }

        self::assertSame(['a=1', 'b=2', 'c=5'], $pairs);
    }

    public function testMergedKeysAreTakenOnlyOnce(): void
    {
        $root = $this->parse("x: &x {a: 1}\nm:\n  <<: [*x, *x]\n");
        $map  = $root->content[3];

        $pairs = [];
        foreach (NodeTools::pairs($map) as [$key, $value]) {
            $pairs[] = $key->value . '=' . $value->value;
        }

        self::assertSame(['a=1'], $pairs);
    }

    #[DataProvider('integerCases')]
    public function testIntegerText(string $text, ?string $expected): void
    {
        self::assertSame($expected, NodeTools::integerText($text));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function integerCases(): iterable
    {
        yield 'zero' => ['0', '0'];

        yield 'negative zero' => ['-0', '0'];

        yield 'positive with a plus' => ['+0042', '42'];

        yield 'upper case hex' => ['0xABC', '2748'];

        yield 'lower case hex' => ['0xabc', '2748'];

        yield 'octal' => ['0o777', '511'];

        yield 'long octal' => ['0o7777777777777777777777', '73786976294838206463'];

        yield 'hex with a prefix' => ['x0x1F', null];

        yield 'hex with a suffix' => ['0x1Fg', null];

        yield 'octal with a prefix' => ['x0o17', null];

        yield 'octal with a suffix' => ['0o17x', null];

        yield 'octal digit out of range' => ['0o8', null];

        yield 'hex without digits' => ['0x', null];

        yield 'empty' => ['', null];

        yield 'double sign' => ['--1', null];

        yield 'float' => ['1e3', null];

        yield 'integer with a suffix' => ['12x', null];

        yield 'integer with a prefix' => ['x12', null];
    }

    #[DataProvider('commentTextCases')]
    public function testCommentText(string $comment, string $expected): void
    {
        self::assertSame($expected, NodeTools::commentText($comment));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commentTextCases(): iterable
    {
        yield 'three lines' => ["# a\n#b\n  # c\n#\n", "a\nb\nc\n\n"];

        yield 'two spaces keep one' => ['#  two', ' two'];

        yield 'plain text line' => ['word', 'word'];

        yield 'indented text line' => ['   text', 'text'];

        yield 'text line with a leading space after trimming' => ['  # x', 'x'];

        yield 'hash inside a line is kept' => ['# a # b', 'a # b'];
    }

    #[DataProvider('trailingCommentCases')]
    public function testTrailingComment(string $expected, string ...$comments): void
    {
        self::assertSame($expected, NodeTools::trailingComment(...$comments));
    }

    /**
     * @return iterable<string, array{string, string, 2?: string}>
     */
    public static function trailingCommentCases(): iterable
    {
        yield 'single' => [' # a', '# a'];

        yield 'lines are folded to spaces' => [' # a b', "# a\n# b"];

        yield 'repeated comments appear once' => [' # same', '# same', '# same'];

        yield 'distinct comments are joined' => [' # one two', '# one', '# two'];

        yield 'no comment' => ['', ''];

        yield 'blank comment text' => ['', '#   '];

        yield 'only a hash' => ['', '#'];

        yield 'several empty comments' => ['', '', ''];
    }

    #[DataProvider('hashCommentCases')]
    public function testHashComment(string $comment, string $indent, string $expected): void
    {
        self::assertSame($expected, NodeTools::hashComment($comment, $indent));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function hashCommentCases(): iterable
    {
        yield 'empty' => ['', '  ', ''];

        yield 'only newlines' => ["\n\n", '  ', ''];

        yield 'two lines with an indent' => ["# a\n b", '  ', "  # a\n  # b\n"];

        yield 'trailing newlines are dropped' => ["# x\n\n", '', "# x\n"];

        yield 'surrounding whitespace is trimmed' => ["  x \t", '', "# x\n"];

        yield 'hash lines are kept' => ["#x\n# y", '>', ">#x\n># y\n"];

        yield 'three plain lines' => ["a\nb\nc", '', "# a\n# b\n# c\n"];

        yield 'empty line inside' => ["a\n\nb", '', "# a\n# \n# b\n"];
    }

    public function testJoinDistinctRepeatsNothingAndKeepsOrder(): void
    {
        $first  = '# first';
        $second = '# second';

        self::assertSame("# first\n# second\n# third", NodeTools::joinDistinct($first, $first, $second, $first, '# third', $second));
    }

    private function parse(string $yaml): Node
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            return $document->root();
        }

        self::fail('no document');
    }
}
