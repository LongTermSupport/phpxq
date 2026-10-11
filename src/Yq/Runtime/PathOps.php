<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use SplObjectStorage;

/**
 * Paths as data: building a path sequence from a match, following a path (optionally creating it),
 * setting a value at a path and deleting matches.
 *
 * @internal
 */
final readonly class PathOps
{
    private function __construct()
    {
    }

    /**
     * The `path` operator's result: a sequence of the keys leading to the match.
     */
    public static function pathNode(Candidate $candidate): Node
    {
        $items = [];
        foreach (Cands::pathKeys($candidate) as $key) {
            $items[] = new Node(NodeKindEnum::Scalar, $key->tag, NodeStyleEnum::Default, $key->value);
        }

        return NodeOps::seq($items);
    }

    /**
     * Follows a path from `$root`; the result is empty when a step does not exist and `$create` is false.
     */
    public static function follow(Candidate $root, bool $create, bool $fixedMerge, Node ...$elements): ?Candidate
    {
        $current = Cands::rooted($root);
        foreach ($elements as $element) {
            $step = Traversal::field($current, $element, $fixedMerge, $create, true);
            if ([] === $step) {
                return null;
            }

            $current = $step[0];
        }

        return $current;
    }

    /**
     * Sets the value at a path, creating the containers on the way.
     */
    public static function set(Candidate $root, Node $value, bool $fixedMerge, Node ...$elements): void
    {
        $target = self::follow($root, true, $fixedMerge, ...$elements);
        if (!$target instanceof Candidate) {
            return;
        }

        Detached::attach($target);
        NodeOps::updateFrom(Cands::node($target), $value);
    }

    /**
     * The elements of a path sequence node.
     *
     * @return list<Node>
     */
    public static function elements(Node $path): array
    {
        $path = NodeOps::deref(NodeOps::unwrap($path));
        if (NodeKindEnum::Sequence === $path->kind) {
            return $path->content;
        }

        return [];
    }

    /**
     * Removes the matched entries from their parents.
     */
    public static function delete(Candidate ...$candidates): void
    {
        /** @var SplObjectStorage<Node, SplObjectStorage<Node, true>> $groups */
        $groups = new SplObjectStorage();
        foreach ($candidates as $candidate) {
            if (Detached::is($candidate->node) || !$candidate->parent instanceof Candidate) {
                continue;
            }

            $container = NodeOps::deref(NodeOps::unwrap($candidate->parent->node));
            if (NodeKindEnum::Mapping !== $container->kind && NodeKindEnum::Sequence !== $container->kind) {
                continue;
            }

            if (!isset($groups[$container])) {
                /** @var SplObjectStorage<Node, true> $set */
                $set                = new SplObjectStorage();
                $groups[$container] = $set;
            }

            $set                   = $groups[$container];
            $set[$candidate->node] = true;
        }

        foreach ($groups as $container) {
            $set = $groups[$container];
            if (NodeKindEnum::Sequence === $container->kind) {
                $kept = [];
                foreach ($container->content as $item) {
                    if (!isset($set[$item])) {
                        $kept[] = $item;
                    }
                }

                $container->content = $kept;

                continue;
            }

            $kept = [];
            for ($i = 0, $n = \count($container->content); $i < $n; $i += 2) {
                $key   = $container->content[$i];
                $value = $container->content[$i + 1];
                if (isset($set[$key]) || isset($set[$value])) {
                    continue;
                }

                $kept[] = $key;
                $kept[] = $value;
            }

            $container->content = $kept;
        }
    }
}
