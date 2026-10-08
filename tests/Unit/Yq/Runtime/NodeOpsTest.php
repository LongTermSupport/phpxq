<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime;

use Generator;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Runtime\NodeOps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The node constructors, classifiers and the in-place update every assignment goes through.
 *
 * @internal
 */
#[CoversClass(NodeOps::class)]
final class NodeOpsTest extends TestCase
{
    private const string CUSTOM = '!custom';

    private const string OLD = 'old';

    private const string NEW = 'new';

    private const int ALIAS_LIMIT = 64;

    private const string NULL_TEXT = 'null';

    private const string FLOAT_TEXT = '1.5';

    private const string PLAIN_TAG = 'plain';

    private const string SCALAR_NAME = 'scalar';

    private const string SOURCE = 'source';

    private const string TARGET = 'target';

    public function testBuildsTheTypedScalars(): void
    {
        $string = NodeOps::str('x');
        self::assertSame([NodeKindEnum::Scalar, CoreSchema::TAG_STR, NodeStyleEnum::Default, 'x'], [$string->kind, $string->tag, $string->style, $string->value]);

        $int = NodeOps::int(-7);
        self::assertSame([NodeKindEnum::Scalar, CoreSchema::TAG_INT, NodeStyleEnum::Default, '-7'], [$int->kind, $int->tag, $int->style, $int->value]);

        $true = NodeOps::bool(true);
        self::assertSame([CoreSchema::TAG_BOOL, NodeOps::TRUE_TEXT, NodeStyleEnum::Default], [$true->tag, $true->value, $true->style]);

        $false = NodeOps::bool(false);
        self::assertSame([CoreSchema::TAG_BOOL, NodeOps::FALSE_TEXT, NodeStyleEnum::Default], [$false->tag, $false->value, $false->style]);

        $null = NodeOps::null();
        self::assertSame([NodeKindEnum::Scalar, CoreSchema::TAG_NULL, NodeStyleEnum::Default, self::NULL_TEXT], [$null->kind, $null->tag, $null->style, $null->value]);

        $empty = NodeOps::emptyNull();
        self::assertSame([NodeKindEnum::Scalar, CoreSchema::TAG_NULL, NodeStyleEnum::Default, ''], [$empty->kind, $empty->tag, $empty->style, $empty->value]);
    }

    #[DataProvider('floatProvider')]
    public function testBuildsFloatsWithTheTagTheirTextResolvesTo(float $value, string $text, string $tag): void
    {
        $node = NodeOps::float($value);

        self::assertSame([NodeKindEnum::Scalar, $tag, NodeStyleEnum::Default, $text], [$node->kind, $node->tag, $node->style, $node->value]);
    }

    /**
     * @return Generator<string, array{float, string, string}>
     */
    public static function floatProvider(): Generator
    {
        yield 'fraction' => [1.5, self::FLOAT_TEXT, CoreSchema::TAG_FLOAT];

        yield 'whole number prints as an int' => [2.0, '2', CoreSchema::TAG_INT];

        yield 'positive infinity' => [\INF, '+Inf', CoreSchema::TAG_STR];

        yield 'negative infinity' => [-\INF, '-Inf', CoreSchema::TAG_STR];

        yield 'not a number' => [\NAN, 'NaN', CoreSchema::TAG_STR];
    }

    public function testBuildsCollections(): void
    {
        $item = NodeOps::int(1);
        $seq  = NodeOps::seq([$item]);
        $map  = NodeOps::map([NodeOps::str('k'), $item]);

        self::assertSame([NodeKindEnum::Sequence, CoreSchema::TAG_SEQ], [$seq->kind, $seq->tag]);
        self::assertSame([$item], $seq->content);
        self::assertSame([NodeKindEnum::Mapping, CoreSchema::TAG_MAP], [$map->kind, $map->tag]);
        self::assertCount(2, $map->content);
        self::assertSame([], NodeOps::seq()->content);
        self::assertSame([], NodeOps::map()->content);
    }

    public function testDerefFollowsAliasesUpToTheLimit(): void
    {
        $target = NodeOps::str('end');

        self::assertSame($target, NodeOps::deref($target));
        self::assertSame($target, NodeOps::deref(Node::alias('a', $target)));
        self::assertSame($target, NodeOps::deref($this->aliasChain($target, self::ALIAS_LIMIT)));
    }

    public function testDerefStopsAtTheAliasLimit(): void
    {
        $target = NodeOps::str('end');
        $chain  = $this->aliasChain($target, self::ALIAS_LIMIT + 1);

        $resolved = NodeOps::deref($chain);

        self::assertSame(NodeKindEnum::Alias, $resolved->kind);
        self::assertNotSame($target, $resolved);
    }

    public function testDerefLeavesAnAliasWithoutATarget(): void
    {
        $alias = new Node(NodeKindEnum::Alias);

        self::assertSame($alias, NodeOps::deref($alias));
    }

    public function testDerefTerminatesOnACycle(): void
    {
        $first              = new Node(NodeKindEnum::Alias);
        $second             = Node::alias('b', $first);
        $first->aliasTarget = $second;

        self::assertSame(NodeKindEnum::Alias, NodeOps::deref($first)->kind);
    }

    public function testDerefDoesNotFollowANonAlias(): void
    {
        $scalar              = NodeOps::str('x');
        $scalar->aliasTarget = NodeOps::str('other');

        self::assertSame($scalar, NodeOps::deref($scalar));
    }

    public function testUnwrapsADocumentToItsRoot(): void
    {
        $root     = NodeOps::str('root');
        $document = Node::document($root);

        self::assertSame($root, NodeOps::unwrap($document));
        self::assertSame($root, NodeOps::unwrap($root));

        $empty = new Node(NodeKindEnum::Document);
        self::assertSame($empty, NodeOps::unwrap($empty));
    }

    #[DataProvider('nullProvider')]
    public function testIsNull(Node $node, bool $expected): void
    {
        self::assertSame($expected, NodeOps::isNull($node));
    }

    /**
     * @return Generator<string, array{Node, bool}>
     */
    public static function nullProvider(): Generator
    {
        yield 'a null' => [NodeOps::null(), true];

        yield 'empty null' => [NodeOps::emptyNull(), true];

        yield 'string' => [NodeOps::str(self::NULL_TEXT), false];

        yield 'mapping tagged null' => [new Node(NodeKindEnum::Mapping, CoreSchema::TAG_NULL), false];
    }

    public function testIsScalar(): void
    {
        self::assertTrue(NodeOps::isScalar(NodeOps::int(1)));
        self::assertFalse(NodeOps::isScalar(NodeOps::seq()));
        self::assertFalse(NodeOps::isScalar(NodeOps::map()));
    }

    #[DataProvider('effectiveTagProvider')]
    public function testEffectiveTag(Node $node, string $expected): void
    {
        self::assertSame($expected, NodeOps::effectiveTag($node));
    }

    /**
     * @return Generator<string, array{Node, string}>
     */
    public static function effectiveTagProvider(): Generator
    {
        yield 'a mapping node' => [NodeOps::map(), CoreSchema::TAG_MAP];

        yield 'a sequence node' => [NodeOps::seq(), CoreSchema::TAG_SEQ];

        yield 'mapping keeps no custom tag' => [new Node(NodeKindEnum::Mapping, self::CUSTOM), CoreSchema::TAG_MAP];

        yield 'alias keeps its tag' => [new Node(NodeKindEnum::Alias, '!!x'), '!!x'];

        yield 'untagged plain int' => [self::scalar('5', ''), CoreSchema::TAG_INT];

        yield 'untagged plain null' => [self::scalar('~', ''), CoreSchema::TAG_NULL];

        yield 'untagged quoted' => [self::scalar('5', '', NodeStyleEnum::SingleQuoted), CoreSchema::TAG_STR];

        yield 'untagged literal' => [self::scalar('5', '', NodeStyleEnum::Literal), CoreSchema::TAG_STR];

        yield 'custom tag on a plain float' => [self::scalar(self::FLOAT_TEXT, self::CUSTOM), CoreSchema::TAG_FLOAT];

        yield 'custom tag on a quoted number' => [self::scalar(self::FLOAT_TEXT, self::CUSTOM, NodeStyleEnum::DoubleQuoted), CoreSchema::TAG_STR];

        yield 'bare bang on a plain bool' => [self::scalar(NodeOps::TRUE_TEXT, '!'), CoreSchema::TAG_BOOL];

        yield 'core tag wins over the value' => [self::scalar('5', CoreSchema::TAG_STR), CoreSchema::TAG_STR];

        yield 'core tag on a quoted value' => [self::scalar('x', CoreSchema::TAG_INT, NodeStyleEnum::SingleQuoted), CoreSchema::TAG_INT];

        yield 'tag without a bang' => [self::scalar('5', self::PLAIN_TAG), self::PLAIN_TAG];

        yield 'double bang only' => [self::scalar('5', '!!'), '!!'];
    }

    #[DataProvider('truthProvider')]
    public function testTruthinessAndTrue(Node $node, bool $truthy, bool $isTrue): void
    {
        self::assertSame($truthy, NodeOps::truthy($node));
        self::assertSame($isTrue, NodeOps::isTrue($node));
    }

    /**
     * @return Generator<string, array{Node, bool, bool}>
     */
    public static function truthProvider(): Generator
    {
        yield 'true bool' => [self::scalar(NodeOps::TRUE_TEXT, CoreSchema::TAG_BOOL), true, true];

        yield 'mixed case true' => [self::scalar('True', CoreSchema::TAG_BOOL), true, true];

        yield 'upper case true' => [self::scalar('TRUE', CoreSchema::TAG_BOOL), true, true];

        yield 'false' => [self::scalar('false', CoreSchema::TAG_BOOL), false, false];

        yield 'upper case false' => [self::scalar('FALSE', CoreSchema::TAG_BOOL), false, false];

        yield 'null scalar' => [NodeOps::null(), false, false];

        yield 'zero' => [NodeOps::int(0), true, false];

        yield 'empty string' => [NodeOps::str(''), true, false];

        yield 'string true is no bool' => [NodeOps::str(NodeOps::TRUE_TEXT), true, false];

        yield 'plain true under a custom tag' => [self::scalar(NodeOps::TRUE_TEXT, self::CUSTOM), true, true];

        yield 'a sequence is truthy' => [NodeOps::seq(), true, false];

        yield 'a mapping is truthy' => [NodeOps::map(), true, false];
    }

    #[DataProvider('kindNameProvider')]
    public function testKindName(Node $node, string $expected): void
    {
        self::assertSame($expected, NodeOps::kindName($node));
    }

    /**
     * @return Generator<string, array{Node, string}>
     */
    public static function kindNameProvider(): Generator
    {
        yield 'mapping' => [NodeOps::map(), 'map'];

        yield 'sequence' => [NodeOps::seq(), 'seq'];

        yield 'alias' => [new Node(NodeKindEnum::Alias), 'alias'];

        yield 'an int' => [NodeOps::int(1), self::SCALAR_NAME];

        yield 'document' => [new Node(NodeKindEnum::Document), self::SCALAR_NAME];
    }

    public function testBecomesAContainer(): void
    {
        $sequence = self::scalar('x', CoreSchema::TAG_STR, NodeStyleEnum::DoubleQuoted);
        NodeOps::becomeContainer($sequence, true);
        self::assertSame([NodeKindEnum::Sequence, CoreSchema::TAG_SEQ, '', NodeStyleEnum::Default, []], [$sequence->kind, $sequence->tag, $sequence->value, $sequence->style, $sequence->content]);

        $mapping = self::scalar('x', CoreSchema::TAG_STR, NodeStyleEnum::DoubleQuoted);
        NodeOps::becomeContainer($mapping, false);
        self::assertSame([NodeKindEnum::Mapping, CoreSchema::TAG_MAP, '', NodeStyleEnum::Default, []], [$mapping->kind, $mapping->tag, $mapping->value, $mapping->style, $mapping->content]);
    }

    public function testUpdatingANodeFromItselfChangesNothing(): void
    {
        $node              = self::scalar(self::OLD, CoreSchema::TAG_STR, NodeStyleEnum::DoubleQuoted);
        $node->headComment = 'head';

        NodeOps::updateFrom($node, $node);

        self::assertSame([self::OLD, NodeStyleEnum::DoubleQuoted, 'head'], [$node->value, $node->style, $node->headComment]);
    }

    public function testUpdateCopiesKindValueContentAndAliasTarget(): void
    {
        $target = self::scalar(self::OLD, CoreSchema::TAG_STR);
        $child  = NodeOps::int(1);
        $source = NodeOps::seq([$child]);

        NodeOps::updateFrom($target, $source);

        self::assertSame([NodeKindEnum::Sequence, CoreSchema::TAG_SEQ, ''], [$target->kind, $target->tag, $target->value]);
        self::assertCount(1, $target->content);
        self::assertSame('1', $target->content[0]->value);

        $aliased = NodeOps::str('t');
        $holder  = self::scalar(self::OLD, CoreSchema::TAG_STR);
        NodeOps::updateFrom($holder, Node::alias('anchor', $aliased));

        self::assertSame(NodeKindEnum::Alias, $holder->kind);
        self::assertSame('anchor', $holder->value);
        self::assertSame($aliased, $holder->aliasTarget);
    }

    public function testUpdateCopiesTheSourceUnlessItIsAdopted(): void
    {
        $child  = NodeOps::int(1);
        $source = NodeOps::seq([$child]);

        $copied = NodeOps::seq();
        NodeOps::updateFrom($copied, $source);
        self::assertNotSame($child, $copied->content[0]);

        $adopted = NodeOps::seq();
        NodeOps::updateFrom($adopted, $source, false, true);
        self::assertSame($child, $adopted->content[0]);
    }

    #[DataProvider('styleProvider')]
    public function testStyleAdoption(Node $target, Node $source, NodeStyleEnum $expected): void
    {
        NodeOps::updateFrom($target, $source);

        self::assertSame($expected, $target->style);
    }

    /**
     * @return Generator<string, array{Node, Node, NodeStyleEnum}>
     */
    public static function styleProvider(): Generator
    {
        yield 'an empty collection takes the style' => [
            new Node(NodeKindEnum::Mapping, CoreSchema::TAG_MAP, NodeStyleEnum::Flow),
            NodeOps::map([NodeOps::str('k'), NodeOps::str('v')]),
            NodeStyleEnum::Default,
        ];

        yield 'a filled collection keeps its style' => [
            new Node(NodeKindEnum::Mapping, CoreSchema::TAG_MAP, NodeStyleEnum::Flow, '', [NodeOps::str('k'), NodeOps::str('v')]),
            NodeOps::map([NodeOps::str('a'), NodeOps::str('b')]),
            NodeStyleEnum::Flow,
        ];

        yield 'an empty scalar takes the style' => [
            self::scalar('', CoreSchema::TAG_STR, NodeStyleEnum::DoubleQuoted),
            self::scalar(self::NEW, CoreSchema::TAG_STR),
            NodeStyleEnum::Default,
        ];

        yield 'a filled scalar keeps its style' => [
            self::scalar(self::OLD, CoreSchema::TAG_STR, NodeStyleEnum::DoubleQuoted),
            self::scalar(self::NEW, CoreSchema::TAG_STR),
            NodeStyleEnum::DoubleQuoted,
        ];

        yield 'a different tag takes the style' => [
            self::scalar(self::OLD, CoreSchema::TAG_STR, NodeStyleEnum::SingleQuoted),
            NodeOps::int(5),
            NodeStyleEnum::Default,
        ];

        yield 'a styled source imposes its style' => [
            self::scalar(self::OLD, CoreSchema::TAG_STR),
            self::scalar(self::NEW, CoreSchema::TAG_STR, NodeStyleEnum::Literal),
            NodeStyleEnum::Literal,
        ];

        yield 'an empty filled sequence keeps a flow source' => [
            NodeOps::seq(),
            Node::sequence([NodeOps::int(1)], NodeStyleEnum::Flow),
            NodeStyleEnum::Flow,
        ];
    }

    #[DataProvider('tagProvider')]
    public function testTagHandling(string $targetTag, string $sourceTag, bool $clobber, string $expectedTag): void
    {
        $target = self::scalar('5', $targetTag);
        $source = self::scalar('7', $sourceTag);

        NodeOps::updateFrom($target, $source, $clobber);

        self::assertSame($expectedTag, $target->tag);
    }

    /**
     * @return Generator<string, array{string, string, bool, string}>
     */
    public static function tagProvider(): Generator
    {
        yield 'a custom tag survives' => [self::CUSTOM, CoreSchema::TAG_INT, false, self::CUSTOM];

        yield 'a custom tag is clobbered on request' => [self::CUSTOM, CoreSchema::TAG_INT, true, CoreSchema::TAG_INT];

        yield 'an empty tag takes the source tag' => ['', CoreSchema::TAG_INT, false, CoreSchema::TAG_INT];

        yield 'a core tag takes the source tag' => [CoreSchema::TAG_STR, CoreSchema::TAG_INT, false, CoreSchema::TAG_INT];

        yield 'a core tag takes a custom source tag' => [CoreSchema::TAG_STR, self::CUSTOM, false, self::CUSTOM];
    }

    #[DataProvider('explicitTagProvider')]
    public function testExplicitTagFlag(bool $sourceExplicit, bool $targetExplicit, string $targetTag, string $sourceTag, bool $expected): void
    {
        $target              = self::scalar('5', $targetTag);
        $target->tagExplicit = $targetExplicit;

        $source              = self::scalar('7', $sourceTag);
        $source->tagExplicit = $sourceExplicit;

        NodeOps::updateFrom($target, $source);

        self::assertSame($expected, $target->tagExplicit);
    }

    /**
     * @return Generator<string, array{bool, bool, string, string, bool}>
     */
    public static function explicitTagProvider(): Generator
    {
        yield 'the source is explicit' => [true, false, CoreSchema::TAG_STR, CoreSchema::TAG_INT, true];

        yield 'the source is explicit and the tags match' => [true, true, CoreSchema::TAG_INT, CoreSchema::TAG_INT, true];

        yield 'only the target is explicit and the tags match' => [false, true, CoreSchema::TAG_INT, CoreSchema::TAG_INT, true];

        yield 'only the target is explicit and the tags differ' => [false, true, CoreSchema::TAG_STR, CoreSchema::TAG_INT, false];

        yield 'neither is explicit' => [false, false, CoreSchema::TAG_INT, CoreSchema::TAG_INT, false];
    }

    public function testExplicitTagFlagIsKeptWhenTheTagIsNotReplaced(): void
    {
        $target              = self::scalar('5', self::CUSTOM);
        $target->tagExplicit = true;

        $source              = self::scalar('7', CoreSchema::TAG_INT);
        $source->tagExplicit = true;

        NodeOps::updateFrom($target, $source);

        self::assertTrue($target->tagExplicit);
        self::assertSame(self::CUSTOM, $target->tag);
    }

    public function testAnAliasSourceResetsTheStyle(): void
    {
        $target       = self::scalar(self::OLD, CoreSchema::TAG_STR, NodeStyleEnum::DoubleQuoted);
        $alias        = Node::alias('a', NodeOps::str('t'));
        $alias->style = NodeStyleEnum::Flow;

        NodeOps::updateFrom($target, $alias);

        self::assertSame(NodeStyleEnum::Default, $target->style);
    }

    #[DataProvider('commentProvider')]
    public function testComments(string $targetComment, string $sourceComment, string $expected): void
    {
        $target              = self::scalar(self::OLD, CoreSchema::TAG_STR);
        $target->headComment = $targetComment;
        $target->lineComment = $targetComment;
        $target->footComment = $targetComment;

        $source              = self::scalar(self::NEW, CoreSchema::TAG_STR);
        $source->headComment = $sourceComment;
        $source->lineComment = $sourceComment;
        $source->footComment = $sourceComment;

        NodeOps::updateFrom($target, $source);

        self::assertSame([$expected, $expected, $expected], [$target->headComment, $target->lineComment, $target->footComment]);
    }

    /**
     * @return Generator<string, array{string, string, string}>
     */
    public static function commentProvider(): Generator
    {
        yield 'source comments replace the target ones' => [self::TARGET, self::SOURCE, self::SOURCE];

        yield 'the target comments stay without a source one' => [self::TARGET, '', self::TARGET];

        yield 'a source comment lands on an uncommented target' => ['', self::SOURCE, self::SOURCE];

        yield 'no comment at all' => ['', '', ''];
    }

    public function testEachCommentIsReplacedIndependently(): void
    {
        $target              = self::scalar(self::OLD, CoreSchema::TAG_STR);
        $target->headComment = 'h1';
        $target->lineComment = 'l1';
        $target->footComment = 'f1';

        $source              = self::scalar(self::NEW, CoreSchema::TAG_STR);
        $source->lineComment = 'l2';

        NodeOps::updateFrom($target, $source);

        self::assertSame(['h1', 'l2', 'f1'], [$target->headComment, $target->lineComment, $target->footComment]);
    }

    private static function scalar(string $value, string $tag, NodeStyleEnum $style = NodeStyleEnum::Default): Node
    {
        return new Node(NodeKindEnum::Scalar, $tag, $style, $value);
    }

    private function aliasChain(Node $target, int $length): Node
    {
        $node = $target;
        for ($i = 0; $i < $length; ++$i) {
            $node = Node::alias('a', $node);
        }

        return $node;
    }
}
