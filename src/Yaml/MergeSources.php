<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml;

/**
 * What a `<<` merge key merges in, resolved one way for navigation, `explode` and every encoder.
 *
 * The value may be a mapping, a sequence whose mappings are merged in order, or an alias of either; each sequence
 * item may itself be an alias. Anything else merges nothing. Alias chains are followed up to MAX_ALIAS_CHAIN
 * links, so a cyclic chain ends and merges nothing.
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

    private static function resolve(Node $node): Node
    {
        for ($link = 0; $link < self::MAX_ALIAS_CHAIN && NodeKindEnum::Alias === $node->kind && $node->aliasTarget instanceof Node; ++$link) {
            $node = $node->aliasTarget;
        }

        return $node;
    }
}
