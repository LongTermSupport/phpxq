<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Parser;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;

/**
 * Renders a node tree as one compact line so a test can state the expected tree as text:
 *
 *  - scalar: `tag/style=value`, for example `!!int=1` or `!!str/double=a b`
 *  - mapping: `{k: v; k2: v2}` (`flow{...}` for a flow mapping)
 *  - sequence: `[a; b]` (`flow[...]` for a flow sequence)
 *  - alias: `*name`; anchor: `&name ` before the node; explicit tag: `!tag ` before the node
 *  - comments follow the node as `#h(head)`, `#l(line)` and `#f(foot)`, newlines shown as ` / `
 *
 * @internal
 */
final class NodeDump
{
    private function __construct()
    {
    }

    public static function dump(Node $node): string
    {
        $out = '';
        if ('' !== $node->anchor) {
            $out .= '&' . $node->anchor . ' ';
        }

        if ($node->tagExplicit) {
            $out .= '!tag:' . $node->tag . ' ';
        }

        $out .= match ($node->kind) {
            NodeKindEnum::Alias    => '*' . $node->value,
            NodeKindEnum::Scalar   => $node->tag . self::style($node->style) . '=' . $node->value,
            NodeKindEnum::Mapping  => self::collection($node, true),
            NodeKindEnum::Sequence => self::collection($node, false),
            NodeKindEnum::Document => self::dump($node->content[0]),
        };

        foreach (['h' => $node->headComment, 'l' => $node->lineComment, 'f' => $node->footComment] as $kind => $text) {
            if ('' !== $text) {
                $out .= '#' . $kind . '(' . str_replace("\n", ' / ', $text) . ')';
            }
        }

        return $out;
    }

    private static function style(NodeStyleEnum $style): string
    {
        return match ($style) {
            NodeStyleEnum::SingleQuoted => '/single',
            NodeStyleEnum::DoubleQuoted => '/double',
            NodeStyleEnum::Literal      => '/literal',
            NodeStyleEnum::Folded       => '/folded',
            default                     => '',
        };
    }

    private static function collection(Node $node, bool $mapping): string
    {
        $parts = [];
        if ($mapping) {
            $counter = \count($node->content);
            for ($i = 0; $i < $counter; $i += 2) {
                $parts[] = self::dump($node->content[$i]) . ': ' . self::dump($node->content[$i + 1]);
            }
        } else {
            foreach ($node->content as $item) {
                $parts[] = self::dump($item);
            }
        }

        $flow = NodeStyleEnum::Flow === $node->style ? 'flow' : '';
        $body = implode('; ', $parts);

        return $mapping ? $flow . '{' . $body . '}' : $flow . '[' . $body . ']';
    }
}
