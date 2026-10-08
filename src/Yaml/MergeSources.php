<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml;

/**
 * What a `<<` merge key merges in.
 *
 * {@see self::of()} is the full form, used by navigation and `explode` in both merge modes and by the encoders
 * under `--yaml-fix-merge-anchor-to-spec`: the value may be a mapping, a sequence whose mappings are merged in
 * order, or an alias of either; each sequence item may itself be an alias; anything else merges nothing.
 * {@see self::aliased()} is the reference's legacy encoder form, which takes aliases only. Alias chains are
 * followed up to MAX_ALIAS_CHAIN links, so a cyclic chain ends.
 */
final readonly class MergeSources
{
    /** The longest chain of aliases followed to reach a node. */
    private const int MAX_ALIAS_CHAIN = 64;

    private function __construct()
    {
    }

    /**
     * The mappings the `<<` value merges in, in the order their keys are offered.
     *
     * @return list<Node>
     */
    public static function of(Node $value): array
    {
        $value = self::resolve($value);
        if (NodeKindEnum::Mapping === $value->kind) {
            return [$value];
        }

        $sources = [];
        if (NodeKindEnum::Sequence === $value->kind) {
            foreach ($value->content as $item) {
                $item = self::resolve($item);
                if (NodeKindEnum::Mapping === $item->kind) {
                    $sources[] = $item;
                }
            }
        }

        return $sources;
    }

    /**
     * The legacy (pre-spec) form the reference's encoders use: only aliases are merge sources, the value itself or
     * the alias items of a sequence, and each yields its target whatever its kind (the caller refuses a target that
     * is not a mapping). Inline mappings, inline items and scalars merge nothing.
     *
     * @return list<Node>
     */
    public static function aliased(Node $value): array
    {
        if (NodeKindEnum::Alias === $value->kind) {
            return [self::resolve($value)];
        }

        $targets = [];
        if (NodeKindEnum::Sequence === $value->kind) {
            foreach ($value->content as $item) {
                if (NodeKindEnum::Alias === $item->kind) {
                    $targets[] = self::resolve($item);
                }
            }
        }

        return $targets;
    }

    private static function resolve(Node $node): Node
    {
        for ($link = 0; $link < self::MAX_ALIAS_CHAIN && NodeKindEnum::Alias === $node->kind && $node->aliasTarget instanceof Node; ++$link) {
            $node = $node->aliasTarget;
        }

        return $node;
    }
}
