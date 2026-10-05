<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\Codec\NodeTools;
use LTS\PhpXq\Yq\Format\FormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NodeToolsTest extends TestCase
{
    public function testUnwrapFollowsDocumentsAndAliases(): void
    {
        $target = Node::scalar('x');
        $alias  = Node::alias('a', $target);

        self::assertSame($target, NodeTools::unwrap(Node::document($alias)));
        self::assertSame($target, NodeTools::unwrap($target));
    }

    public function testUnwrapOfEmptyDocumentIsNull(): void
    {
        $unwrapped = NodeTools::unwrap(new Node(NodeKindEnum::Document));

        self::assertSame('!!null', $unwrapped->tag);
    }

    public function testPairsExpandMergeKeysWithExplicitKeysWinning(): void
    {
        $root = $this->parse("base: &b\n  a: 1\n  b: 2\nderived:\n  <<: *b\n  b: 3\n  c: 4\n");
        $map  = $root->content[3];

        $pairs = [];
        foreach (NodeTools::pairs($map) as [$key, $value]) {
            $pairs[] = $key->value . '=' . $value->value;
        }

        self::assertSame(['a=1', 'b=3', 'c=4'], $pairs);
    }

    public function testPairsExpandMergeSequences(): void
    {
        $root = $this->parse("x: &x {a: 1}\ny: &y {a: 2, b: 3}\nz:\n  <<: [*x, *y]\n");
        $map  = $root->content[5];

        $pairs = [];
        foreach (NodeTools::pairs($map) as [$key, $value]) {
            $pairs[] = $key->value . '=' . $value->value;
        }

        self::assertSame(['a=1', 'b=3'], $pairs);
    }

    public function testKeyTextRejectsCollections(): void
    {
        $this->expectException(FormatException::class);
        NodeTools::keyText(Node::sequence());
    }

    #[DataProvider('integerCases')]
    public function testIntegerText(string $yaml, ?string $expected): void
    {
        self::assertSame($expected, NodeTools::integerText($yaml));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function integerCases(): iterable
    {
        yield 'plain' => ['42', '42'];

        yield 'signed' => ['+7', '7'];

        yield 'negative leading zeros' => ['-007', '-7'];

        yield 'zero' => ['000', '0'];

        yield 'hex' => ['0x1F', '31'];

        yield 'octal' => ['0o30', '24'];

        yield 'big hex' => ['0xFFFFFFFFFFFFFFFFFF', '4722366482869645213695'];

        yield 'not an int' => ['1.5', null];
    }

    #[DataProvider('commentCases')]
    public function testCommentText(string $comment, string $expected): void
    {
        self::assertSame($expected, NodeTools::commentText($comment));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commentCases(): iterable
    {
        yield 'single' => ['# hello', 'hello'];

        yield 'multi' => ["# a\n# b", "a\nb"];

        yield 'empty hash lines' => ["#\n# a\n#", "\na\n"];

        yield 'no space' => ['#a', 'a'];

        yield 'empty' => ['', ''];
    }

    public function testJoinCommentsSkipsEmptyOnes(): void
    {
        self::assertSame("# a\n# b", NodeTools::joinComments('# a', '', '# b'));
        self::assertSame('', NodeTools::joinComments('', ''));
        self::assertSame('# a', NodeTools::joinDistinct('# a', '# a'));
        self::assertSame("# a\n# b", NodeTools::joinDistinct('# a', '# b', '# a'));
    }

    public function testReplaceAtKeepsAList(): void
    {
        $sequence = Node::sequence([Node::scalar('a'), Node::scalar('b')]);
        NodeTools::replaceAt($sequence, 1, Node::scalar('c'));

        self::assertSame(['a', 'c'], array_map(static fn (Node $node): string => $node->value, $sequence->content));
    }

    public function testFlatContentExpandsMergesOnlyWhenPresent(): void
    {
        $plain = Node::mapping([Node::scalar('a'), Node::scalar('1')]);
        self::assertSame($plain->content, NodeTools::flatContent($plain));

        $root   = $this->parse("base: &b {x: 1}\nm:\n  <<: *b\n  y: 2\n");
        $values = array_map(static fn (Node $node): string => $node->value, NodeTools::flatContent($root->content[3]));

        self::assertSame(['x', '1', 'y', '2'], $values);
    }

    public function testMergeKeyDetection(): void
    {
        self::assertTrue(NodeTools::isMergeKey(Node::scalar('<<', '!!str')));
        self::assertFalse(NodeTools::isMergeKey(Node::scalar('<<', '!!str', NodeStyleEnum::DoubleQuoted)));
        self::assertFalse(NodeTools::isMergeKey(Node::scalar('a')));
    }

    public function testKeyTextFollowsAliases(): void
    {
        self::assertSame('k', NodeTools::keyText(Node::alias('a', Node::scalar('k'))));
    }

    public function testAliasChainsThatNeverEndAreRejected(): void
    {
        $alias              = Node::alias('a', Node::scalar('x'));
        $alias->aliasTarget = $alias;

        $this->expectException(FormatException::class);
        NodeTools::unwrap($alias);
    }

    private function parse(string $yaml): Node
    {
        foreach (new YamlParser()->parse($yaml) as $document) {
            return $document->root();
        }

        self::fail('no document');
    }
}
