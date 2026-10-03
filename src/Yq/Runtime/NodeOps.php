<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yaml\Schema\CoreSchema;

/**
 * Small constructors and predicates over {@see Node} shared by the operators: typed scalar factories,
 * alias dereferencing, truthiness, effective tags (custom tags resolve by value) and the in-place
 * update the assignment operators use.
 */
final class NodeOps
{
    private const int MAX_ALIAS_DEPTH = 64;

    private function __construct()
    {
    }

    public static function str(string $value): Node
    {
        return new Node(NodeKind::Scalar, CoreSchema::TAG_STR, NodeStyle::Default, $value);
    }

    public static function int(int $value): Node
    {
        return new Node(NodeKind::Scalar, CoreSchema::TAG_INT, NodeStyle::Default, (string)$value);
    }

    /**
     * A float result. The tag is the one the text resolves to, so a whole number prints as `2` and
     * `+Inf` or `NaN` print bare, as the reference prints them.
     */
    public static function float(float $value): Node
    {
        $text = Numbers::formatFloat($value);

        return new Node(NodeKind::Scalar, CoreSchema::resolve($text), NodeStyle::Default, $text);
    }

    /**
     * A null with no text, how the reference pads missing cells (it prints as nothing at all).
     */
    public static function emptyNull(): Node
    {
        return new Node(NodeKind::Scalar, CoreSchema::TAG_NULL, NodeStyle::Default, '');
    }

    public static function bool(bool $value): Node
    {
        return new Node(NodeKind::Scalar, CoreSchema::TAG_BOOL, NodeStyle::Default, $value ? 'true' : 'false');
    }

    public static function null(): Node
    {
        return new Node(NodeKind::Scalar, CoreSchema::TAG_NULL, NodeStyle::Default, 'null');
    }

    /**
     * @param list<Node> $items
     */
    public static function seq(array $items = []): Node
    {
        return Node::sequence($items);
    }

    /**
     * @param list<Node> $flat key0, value0, key1, value1, ...
     */
    public static function map(array $flat = []): Node
    {
        return Node::mapping($flat);
    }

    /**
     * The node an alias stands for (the node itself when it is not an alias).
     */
    public static function deref(Node $node): Node
    {
        $depth = 0;
        while (NodeKind::Alias === $node->kind && $node->aliasTarget instanceof Node && $depth < self::MAX_ALIAS_DEPTH) {
            $node = $node->aliasTarget;
            ++$depth;
        }

        return $node;
    }

    /**
     * A Document is transparent: operators work on its root.
     */
    public static function unwrap(Node $node): Node
    {
        if (NodeKind::Document === $node->kind && isset($node->content[0])) {
            return $node->content[0];
        }

        return $node;
    }

    public static function isNull(Node $node): bool
    {
        return NodeKind::Scalar === $node->kind && CoreSchema::TAG_NULL === $node->tag;
    }

    public static function isScalar(Node $node): bool
    {
        return NodeKind::Scalar === $node->kind;
    }

    /**
     * The tag the reference treats the node as having: a custom tag on a scalar (`!horse 1.2`) resolves by
     * its value so arithmetic and comparison still work.
     */
    public static function effectiveTag(Node $node): string
    {
        if (NodeKind::Scalar !== $node->kind) {
            return match ($node->kind) {
                NodeKind::Mapping  => CoreSchema::TAG_MAP,
                NodeKind::Sequence => CoreSchema::TAG_SEQ,
                default            => $node->tag,
            };
        }

        $tag = $node->tag;
        if ('' === $tag) {
            return NodeStyle::Default === $node->style ? CoreSchema::resolve($node->value) : CoreSchema::TAG_STR;
        }

        if ('!' === $tag[0] && !str_starts_with($tag, '!!')) {
            return NodeStyle::Default === $node->style ? CoreSchema::resolve($node->value) : CoreSchema::TAG_STR;
        }

        return $tag;
    }

    public static function isTrue(Node $node): bool
    {
        return NodeKind::Scalar === $node->kind && CoreSchema::TAG_BOOL === self::effectiveTag($node) && 'true' === strtolower($node->value);
    }

    /**
     * yq truthiness: null and the boolean false are falsy, everything else (0, "", collections) is truthy.
     */
    public static function truthy(Node $node): bool
    {
        if (NodeKind::Scalar !== $node->kind) {
            return true;
        }

        if (CoreSchema::TAG_NULL === $node->tag) {
            return false;
        }

        if (CoreSchema::TAG_BOOL === $node->tag) {
            return 'true' === strtolower($node->value);
        }

        return true;
    }

    public static function kindName(Node $node): string
    {
        return match ($node->kind) {
            NodeKind::Mapping  => 'map',
            NodeKind::Sequence => 'seq',
            NodeKind::Alias    => 'alias',
            default            => 'scalar',
        };
    }

    /**
     * Keeps every key of a mapping addressable by its scalar text.
     */
    public static function isMergeKey(Node $key): bool
    {
        return NodeKind::Scalar === $key->kind && '<<' === $key->value && NodeStyle::Default === $key->style;
    }

    /**
     * The text a scalar contributes where a string is wanted; collections have none.
     */
    public static function scalarText(Node $node): string
    {
        $node = self::deref($node);

        return NodeKind::Scalar === $node->kind ? $node->value : '';
    }

    /**
     * Overwrites `$target` in place with `$source`, the reference's UpdateFrom: kind, value and content come
     * from the source, the target keeps its anchor, position and (when the source has none) comments, a
     * custom tag survives unless `$clobberTags`, and the style is adopted only by an empty target.
     *
     * The source is copied unless `$adopt`: a freshly computed source (not a node still in a document)
     * hands its children over as they are, which keeps nodes selected earlier valid targets of later updates.
     */
    public static function updateFrom(Node $target, Node $source, bool $clobberTags = false, bool $adopt = false): void
    {
        if ($target === $source) {
            return;
        }

        $copy = $adopt ? $source : $source->deepCopy();

        if ((NodeKind::Scalar !== $target->kind && [] === $target->content) || (NodeKind::Scalar === $target->kind && '' === $target->value) || self::effectiveTag($target) !== self::effectiveTag($copy)) {
            $target->style = $copy->style;
        }

        if ($clobberTags || '' === $target->tag || str_starts_with($target->tag, '!!')) {
            $target->tag         = $copy->tag;
            $target->tagExplicit = $copy->tagExplicit;
        }

        $target->kind        = $copy->kind;
        $target->value       = $copy->value;
        $target->content     = $copy->content;
        $target->aliasTarget = $copy->aliasTarget;

        if (NodeKind::Alias === $copy->kind) {
            $target->style = NodeStyle::Default;
        }

        if ('' !== $copy->headComment) {
            $target->headComment = $copy->headComment;
        }

        if ('' !== $copy->lineComment) {
            $target->lineComment = $copy->lineComment;
        }

        if ('' !== $copy->footComment) {
            $target->footComment = $copy->footComment;
        }
    }

    /**
     * Turns a null placeholder into an empty mapping or sequence so a child can be attached.
     */
    public static function becomeContainer(Node $node, bool $sequence): void
    {
        $node->kind    = $sequence ? NodeKind::Sequence : NodeKind::Mapping;
        $node->tag     = $sequence ? CoreSchema::TAG_SEQ : CoreSchema::TAG_MAP;
        $node->value   = '';
        $node->style   = NodeStyle::Default;
        $node->content = [];
    }
}
