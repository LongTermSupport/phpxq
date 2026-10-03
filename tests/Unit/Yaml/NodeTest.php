<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class NodeTest extends TestCase
{
    public function testScalarResolvesPlainTagFromTheCoreSchema(): void
    {
        self::assertSame('!!int', Node::scalar('12')->tag);
        self::assertSame('!!str', Node::scalar('12', '', NodeStyle::DoubleQuoted)->tag);
        self::assertSame('!!binary', Node::scalar('aGk=', '!!binary')->tag);
    }

    public function testCollectionFactories(): void
    {
        $key     = Node::scalar('a');
        $value   = Node::scalar('1');
        $mapping = Node::mapping([$key, $value]);
        $seq     = Node::sequence([$value]);

        self::assertSame(NodeKind::Mapping, $mapping->kind);
        self::assertSame('!!map', $mapping->tag);
        self::assertSame([$key, $value], $mapping->content);
        self::assertSame('!!seq', $seq->tag);
    }

    public function testRootOfDocument(): void
    {
        $root = Node::scalar('x');
        $doc  = Node::document($root);

        self::assertSame(NodeKind::Document, $doc->kind);
        self::assertSame($root, $doc->root());
        self::assertSame($root, $root->root());
    }

    public function testDeepCopyDoesNotShareNodes(): void
    {
        $value              = Node::scalar('1');
        $value->headComment = '# head';

        $mapping = Node::mapping([Node::scalar('a'), $value]);

        $copy                    = $mapping->deepCopy();
        $copy->content[1]->value = '2';

        self::assertSame('1', $value->value);
        self::assertSame('# head', $copy->content[1]->headComment);
    }

    public function testDeepCopyRepointsAliasesInsideTheCopiedTree(): void
    {
        $anchored         = Node::scalar('v');
        $anchored->anchor = 'x';

        $alias            = Node::alias('x', $anchored);
        $mapping          = Node::mapping([Node::scalar('a'), $anchored, Node::scalar('b'), $alias]);

        $copy = $mapping->deepCopy();

        self::assertSame($copy->content[1], $copy->content[3]->aliasTarget);
        self::assertNotSame($anchored, $copy->content[3]->aliasTarget);
    }

    public function testDeepCopyKeepsAliasesToOutsideTargets(): void
    {
        $outside = Node::scalar('v');
        $alias   = Node::alias('x', $outside);

        self::assertSame($outside, Node::sequence([$alias])->deepCopy()->content[0]->aliasTarget);
    }
}
