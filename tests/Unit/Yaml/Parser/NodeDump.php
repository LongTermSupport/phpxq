<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yaml\Parser;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;

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
            NodeKind::Alias    => '*' . $node->value,
            NodeKind::Scalar   => $node->tag . self::style($node->style) . '=' . $node->value,
            NodeKind::Mapping  => self::collection($node, true),
            NodeKind::Sequence => self::collection($node, false),
            NodeKind::Document => self::dump($node->content[0]),
        };

        foreach (['h' => $node->headComment, 'l' => $node->lineComment, 'f' => $node->footComment] as $kind => $text) {
            if ('' !== $text) {
                $out .= '#' . $kind . '(' . str_replace("\n", ' / ', $text) . ')';
            }
        }

        return $out;
    }

    private static function style(NodeStyle $style): string
    {
        return match ($style) {
            NodeStyle::SingleQuoted => '/single',
            NodeStyle::DoubleQuoted => '/double',
            NodeStyle::Literal      => '/literal',
            NodeStyle::Folded       => '/folded',
            default                 => '',
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

        $flow = NodeStyle::Flow === $node->style ? 'flow' : '';
        $body = implode('; ', $parts);

        return $mapping ? $flow . '{' . $body . '}' : $flow . '[' . $body . ']';
    }
}
