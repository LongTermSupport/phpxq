<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml;

use LTS\PhpXq\Cli\Xdebug;
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
 * reference, not a copy); its `value` is the anchor name. `leadingContent` is the comment and blank lines
 * the CLI slurped ahead of a first document, `# ` markers included; `commentsCleared` is set on a Document
 * whose comments were assigned empty, so that slurped header is dropped with them.
 *
 * @internal
 */
final class Node
{
    /**
     * Deepest accepted nesting of collections in one document when no Xdebug mode is active: go-yaml's own
     * limit. Readers, the evaluator and the writers walk a tree recursively, so a depth the native stack cannot
     * hold would end the process with a segmentation fault; every reader refuses deeper input with a plain
     * error instead.
     */
    public const int MAX_DEPTH = 10000;

    /**
     * The limit while an Xdebug mode (coverage, develop, debug) is active: every call then uses much more native
     * stack, and the recursive-descent YAML parser overflows the default 8 MB stack a little below 10000 levels
     * (measured: 9500 pass, 10000 do not), so the limit keeps a margin of about two.
     */
    public const int MAX_DEPTH_UNDER_XDEBUG = 5000;

    /** The error text for input nested deeper than the limit; the placeholder is the limit. */
    private const string DEPTH_ERROR_FORMAT = 'exceeded max depth of %d';

    public bool $commentsCleared = false;

    // The rarely used properties are plain declarations with defaults rather than promoted constructor
    // parameters: the engine copies declared defaults in one block, while every promoted parameter costs an
    // assignment per construction (benchmark yq:identity-medium; results-yq.md, optimisation 1).
    public string $anchor = '';

    public ?self $aliasTarget = null;

    public string $headComment = '';

    public string $lineComment = '';

    public string $footComment = '';

    public bool $tagExplicit = false;

    public bool $explicitStart = false;

    public bool $explicitEnd = false;

    public string $directives = '';

    public string $leadingContent = '';

    /**
     * @param list<Node> $content
     */
    public function __construct(
        public NodeKindEnum $kind,
        public string $tag = '',
        public NodeStyleEnum $style = NodeStyleEnum::Default,
        public string $value = '',
        public array $content = [],
        public int $line = 0,
        public int $column = 0,
    ) {
    }

    /**
     * The deepest accepted nesting for this process.
     */
    public static function maxDepth(): int
    {
        return Xdebug::active() ? self::MAX_DEPTH_UNDER_XDEBUG : self::MAX_DEPTH;
    }

    /**
     * Whether a collection opened at this depth (the outermost collection is depth 1) is over the limit.
     */
    public static function depthExceeded(int $depth): bool
    {
        return $depth > self::maxDepth();
    }

    /**
     * The text of the error a reader raises for input nested deeper than {@see self::maxDepth()}.
     */
    public static function depthError(): string
    {
        return \sprintf(self::DEPTH_ERROR_FORMAT, self::maxDepth());
    }

    /**
     * A scalar. Pass an empty tag to have the core schema resolve it from a plain value; quoted and block
     * scalars resolve to !!str.
     */
    public static function scalar(string $value, string $tag = '', NodeStyleEnum $style = NodeStyleEnum::Default): self
    {
        if ('' === $tag) {
            $tag = NodeStyleEnum::Default === $style ? CoreSchema::resolve($value) : CoreSchema::TAG_STR;
        }

        return new self(NodeKindEnum::Scalar, $tag, $style, $value);
    }

    /**
     * @param list<Node> $items
     */
    public static function sequence(array $items = [], NodeStyleEnum $style = NodeStyleEnum::Default): self
    {
        return new self(NodeKindEnum::Sequence, CoreSchema::TAG_SEQ, $style, '', $items);
    }

    /**
     * @param list<Node> $keysAndValues the flat list key0, value0, key1, value1, ...
     */
    public static function mapping(array $keysAndValues = [], NodeStyleEnum $style = NodeStyleEnum::Default): self
    {
        return new self(NodeKindEnum::Mapping, CoreSchema::TAG_MAP, $style, '', $keysAndValues);
    }

    public static function document(self $root): self
    {
        return new self(NodeKindEnum::Document, '', NodeStyleEnum::Default, '', [$root]);
    }

    public static function alias(string $anchorName, self $target): self
    {
        $node              = new self(NodeKindEnum::Alias, '', NodeStyleEnum::Default, $anchorName);
        $node->aliasTarget = $target;

        return $node;
    }

    /**
     * The first content node of a Document, or the node itself otherwise.
     */
    public function root(): self
    {
        if (NodeKindEnum::Document === $this->kind && isset($this->content[0])) {
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
        // Hot path (benchmarks yq:group-medium, yq:select-medium): copy without any bookkeeping and only
        // pair originals with copies, to re-point aliases, when the tree holds an alias at all.
        $hasAlias = false;
        $copy     = $this->copyPlain($hasAlias);
        if ($hasAlias) {
            /** @var SplObjectStorage<Node, Node> $map */
            $map = new SplObjectStorage();
            self::pair($this, $copy, $map);
            self::repointAliases($copy, $map);
        }

        return $copy;
    }

    private function copyPlain(bool &$hasAlias): self
    {
        $copy = clone $this;
        if ($this->aliasTarget instanceof self) {
            $hasAlias = true;
        }

        if ([] !== $this->content) {
            $copied = [];
            foreach ($this->content as $child) {
                $copied[] = [] === $child->content && !$child->aliasTarget instanceof self ? clone $child : $child->copyPlain($hasAlias);
                if ($child->aliasTarget instanceof self) {
                    $hasAlias = true;
                }
            }

            $copy->content = $copied;
        }

        return $copy;
    }

    /**
     * Records which copy stands for which original, walking both trees in step.
     *
     * @param SplObjectStorage<Node, Node> $map
     */
    private static function pair(self $original, self $copy, SplObjectStorage $map): void
    {
        $map[$original] = $copy;
        foreach ($original->content as $index => $child) {
            self::pair($child, $copy->content[$index], $map);
        }
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
