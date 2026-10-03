<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml;

use LTS\PhpXq\Yaml\Schema\CoreSchema;
use SplObjectStorage;

/**
 * The single YAML node type shared by parser, emitter, evaluator and format codecs.
 *
 * One mutable class (not a class per kind) keeps allocation low and lets yq-style in-place updates
 * rewrite a node without rebuilding its parents. Public properties are deliberate: this is a data
 * structure, and the hot paths read it directly.
 *
 * Mapping `content` is the flat list key0, value0, key1, value1, ...; key nodes keep their own
 * comments, style and anchors. An Alias node keeps the shared target in `aliasTarget` (an object
 * reference, not a copy); its `value` is the anchor name.
 */
final class Node
{
    /**
     * @param list<Node> $content
     */
    public function __construct(
        public NodeKind $kind,
        public string $tag = '',
        public NodeStyle $style = NodeStyle::Default,
        public string $value = '',
        public array $content = [],
        public string $anchor = '',
        public ?self $aliasTarget = null,
        public string $headComment = '',
        public string $lineComment = '',
        public string $footComment = '',
        public int $line = 0,
        public int $column = 0,
        public bool $tagExplicit = false,
        public bool $explicitStart = false,
        public bool $explicitEnd = false,
        public string $directives = '',
    ) {
    }

    /**
     * A scalar. Pass an empty tag to have the core schema resolve it from a plain value; quoted and block
     * scalars resolve to !!str.
     */
    public static function scalar(string $value, string $tag = '', NodeStyle $style = NodeStyle::Default): self
    {
        if ('' === $tag) {
            $tag = NodeStyle::Default === $style ? CoreSchema::resolve($value) : CoreSchema::TAG_STR;
        }

        return new self(NodeKind::Scalar, $tag, $style, $value);
    }

    /**
     * @param list<Node> $items
     */
    public static function sequence(array $items = [], NodeStyle $style = NodeStyle::Default): self
    {
        return new self(NodeKind::Sequence, CoreSchema::TAG_SEQ, $style, '', $items);
    }

    /**
     * @param list<Node> $keysAndValues the flat list key0, value0, key1, value1, ...
     */
    public static function mapping(array $keysAndValues = [], NodeStyle $style = NodeStyle::Default): self
    {
        return new self(NodeKind::Mapping, CoreSchema::TAG_MAP, $style, '', $keysAndValues);
    }

    public static function document(self $root): self
    {
        return new self(NodeKind::Document, '', NodeStyle::Default, '', [$root]);
    }

    public static function alias(string $anchorName, self $target): self
    {
        $node              = new self(NodeKind::Alias, '', NodeStyle::Default, $anchorName);
        $node->aliasTarget = $target;

        return $node;
    }

    /**
     * The first content node of a Document, or the node itself otherwise.
     */
    public function root(): self
    {
        if (NodeKind::Document === $this->kind && isset($this->content[0])) {
            return $this->content[0];
        }

        return $this;
    }

    /**
     * A deep copy. An alias whose target lies inside the copied tree is re-pointed at the copy of that
     * target; an alias to a node outside the tree keeps pointing at the original.
     */
    public function deepCopy(): self
    {
        /** @var SplObjectStorage<Node, Node> $map */
        $map  = new SplObjectStorage();
        $copy = $this->copyInto($map);

        self::repointAliases($copy, $map);

        return $copy;
    }

    /**
     * @param SplObjectStorage<Node, Node> $map
     */
    private function copyInto(SplObjectStorage $map): self
    {
        $copy          = clone $this;
        $copy->content = [];
        foreach ($this->content as $child) {
            $copy->content[] = $child->copyInto($map);
        }

        $map[$this] = $copy;

        return $copy;
    }

    /**
     * @param SplObjectStorage<Node, Node> $map
     */
    private static function repointAliases(self $copy, SplObjectStorage $map): void
    {
        if ($copy->aliasTarget instanceof self && isset($map[$copy->aliasTarget])) {
            $copy->aliasTarget = $map[$copy->aliasTarget];
        }

        foreach ($copy->content as $child) {
            self::repointAliases($child, $map);
        }
    }
}
