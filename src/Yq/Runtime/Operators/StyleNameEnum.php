<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

use LTS\PhpXq\Yaml\NodeStyleEnum;

/**
 * The names the `style` operator reads and writes for a node's presentation style.
 */
enum StyleNameEnum: string
{
    /**
     * The name of a node style, or null for the default style (which has none unless the tag is explicit).
     */
    public static function fromNodeStyle(NodeStyleEnum $style): ?self
    {
        return match ($style) {
            NodeStyleEnum::DoubleQuoted => self::Double,
            NodeStyleEnum::SingleQuoted => self::Single,
            NodeStyleEnum::Literal      => self::Literal,
            NodeStyleEnum::Folded       => self::Folded,
            NodeStyleEnum::Flow         => self::Flow,
            default                     => null,
        };
    }

    public function nodeStyle(): NodeStyleEnum
    {
        return match ($this) {
            self::Double  => NodeStyleEnum::DoubleQuoted,
            self::Single  => NodeStyleEnum::SingleQuoted,
            self::Literal => NodeStyleEnum::Literal,
            self::Folded  => NodeStyleEnum::Folded,
            self::Flow    => NodeStyleEnum::Flow,
            self::Tagged  => NodeStyleEnum::Default,
        };
    }

    case Tagged = 'tagged';

    case Double = 'double';

    case Single = 'single';

    case Literal = 'literal';

    case Folded = 'folded';

    case Flow = 'flow';
}
