<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml;

/**
 * The one test of whether a mapping key is a `<<` merge key, as the reference (yq 4.54) decides it for each use.
 *
 * Navigation (`.a`, `.[]`, `keys`) goes by the `!!merge` tag alone, which a plain `<<` resolves to. What writes a
 * mapping out with its merges expanded (the encoders, the alias budget that guards them, and `explode`) takes any
 * scalar `<<`, whatever its tag or quoting, in the legacy mode, and only a `<<` tagged `!!merge` under
 * `--yaml-fix-merge-anchor-to-spec`. Every consumer asks here, so the alias budget counts a merge exactly when
 * the writer it guards performs one.
 *
 * @internal
 */
final readonly class MergeKey
{
    /** The text of a merge key. */
    public const string NAME = '<<';

    /** The tag a plain `<<` resolves to. */
    public const string TAG = '!!merge';

    private function __construct()
    {
    }

    /**
     * Whether navigation treats the key as a merge key.
     */
    public static function navigates(Node $key): bool
    {
        return NodeKindEnum::Scalar === $key->kind && self::TAG === $key->tag;
    }

    /**
     * Whether writing the mapping out (an encoder, `explode`) merges through the key.
     */
    public static function merges(Node $key, bool $fixedMerge): bool
    {
        return NodeKindEnum::Scalar === $key->kind && self::NAME === $key->value && (!$fixedMerge || self::TAG === $key->tag);
    }
}
