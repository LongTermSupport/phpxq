<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Limits\AllocationLimit;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use WeakMap;

/**
 * Null placeholders produced by traversing a path that does not exist (`.a.b.c` on `{}`). They are
 * linked to their parent candidate but not yet part of the tree: reading them gives null, and the first
 * mutation attaches the whole chain, creating mappings (or sequences, for an integer key) on the way, as
 * the reference auto-creates paths.
 */
final class Detached
{
    /** @var WeakMap<Node, true>|null */
    private static ?WeakMap $pending = null;

    private function __construct()
    {
    }

    public static function mark(Node $node): void
    {
        self::registry()[$node] = true;
    }

    public static function is(Node $node): bool
    {
        return isset(self::registry()[$node]);
    }

    /**
     * Makes the candidate (and every placeholder above it) part of the tree.
     *
     * @throws EvaluationException when a sequence would be padded past {@see AllocationLimit::MAX_PADDING}
     */
    public static function attach(Candidate $candidate): void
    {
        if (!self::is($candidate->node)) {
            return;
        }

        $parentCandidate = $candidate->parent;
        $key             = $candidate->key;
        if (!$parentCandidate instanceof Candidate || !$key instanceof Node) {
            unset(self::registry()[$candidate->node]);

            return;
        }

        self::attach($parentCandidate);

        $parent = NodeOps::deref(NodeOps::unwrap($parentCandidate->node));
        if (NodeKindEnum::Scalar === $parent->kind && (CoreSchema::TAG_NULL === $parent->tag || '' === $parent->value)) {
            NodeOps::becomeContainer($parent, CoreSchema::TAG_INT === $key->tag);
        }

        if ([] === $parent->content) {
            $parent->style = NodeStyleEnum::Default;
        }

        if (NodeKindEnum::Sequence === $parent->kind) {
            $index = (int)$key->value;
            if (AllocationLimit::padsTooFar($index, \count($parent->content))) {
                throw new EvaluationException(AllocationLimit::paddingError($index));
            }

            while (\count($parent->content) < $index) {
                $parent->content[] = NodeOps::null();
            }

            $parent->content[] = $candidate->node;
        } elseif (NodeKindEnum::Mapping === $parent->kind) {
            $parent->content[] = $key;
            $parent->content[] = $candidate->node;
        }

        unset(self::registry()[$candidate->node]);
    }

    /**
     * @return WeakMap<Node, true>
     */
    private static function registry(): WeakMap
    {
        if (!self::$pending instanceof WeakMap) {
            /** @var WeakMap<Node, true> $created */
            $created       = new WeakMap();
            self::$pending = $created;
        }

        return self::$pending;
    }
}
