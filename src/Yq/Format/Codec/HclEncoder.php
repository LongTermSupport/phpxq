<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * HCL output, the inverse of {@see HclReader}: scalars, lists and marked object values become attributes
 * (`name = value`), other mappings become blocks (a mapping whose `explicitEnd` flag is set holds block
 * labels as its keys), a sequence of mappings becomes repeated blocks. Strings are double-quoted unless the
 * reader flagged them as raw expressions. Two spaces per level, no blank lines.
 */
final class HclEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 500;

    private const string ASSIGN = ' = ';

    public function format(): FormatEnum
    {
        return FormatEnum::Hcl;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::unwrap($node);
        if (NodeKindEnum::Scalar === $root->kind) {
            return $root->value . "\n";
        }

        if (NodeKindEnum::Mapping !== $root->kind) {
            throw new FormatException('hcl: only a map can be written as an HCL document');
        }

        $out = '';
        $this->body($out, $root, 0, 0);
        $footer = $this->comment($node->footComment, '');

        return $out . $footer;
    }

    private function body(string &$out, Node $map, int $level, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('hcl: exceeded max depth (alias cycle?)');
        }

        $indent = str_repeat('  ', $level);
        foreach (NodeTools::pairs($map) as [$key, $value]) {
            $name     = $this->name(NodeTools::keyText($key));
            $resolved = NodeTools::unwrap($value);
            $out     .= $this->comment($key->headComment, $indent);

            if (NodeKindEnum::Mapping === $resolved->kind && !$resolved->explicitStart) {
                $this->block($out, $name, $resolved, [], $level, $depth);
            } elseif ($this->isBlockList($resolved)) {
                foreach ($resolved->content as $item) {
                    $this->block($out, $name, NodeTools::unwrap($item), [], $level, $depth);
                }
            } else {
                $out .= $indent . $name . self::ASSIGN . $this->value($resolved, $level, false, $depth + 1) . $this->trailing($key->lineComment, $value->lineComment, $resolved->lineComment) . "\n";
            }

            $out .= $this->comment($key->footComment, $indent);
        }
    }

    /**
     * @param list<string> $labels
     */
    private function block(string &$out, string $type, Node $map, array $labels, int $level, int $depth): void
    {
        $indent = str_repeat('  ', $level);
        if ($map->explicitEnd) {
            foreach (NodeTools::pairs($map) as [$labelKey, $labelValue]) {
                $inner = NodeTools::unwrap($labelValue);
                if (NodeKindEnum::Mapping !== $inner->kind) {
                    throw new FormatException('hcl: a block label must hold a map');
                }

                $this->block($out, $type, $inner, [...$labels, NodeTools::keyText($labelKey)], $level, $depth + 1);
            }

            return;
        }

        $header = $type;
        foreach ($labels as $label) {
            $header .= ' ' . $this->quote($label);
        }

        $out .= $indent . $header . " {\n";
        $this->body($out, $map, $level + 1, $depth + 1);
        $out .= $indent . "}\n";
    }

    private function isBlockList(Node $node): bool
    {
        if (NodeKindEnum::Sequence !== $node->kind || [] === $node->content) {
            return false;
        }

        foreach ($node->content as $item) {
            $item = NodeTools::unwrap($item);
            if (NodeKindEnum::Mapping !== $item->kind || $item->explicitStart) {
                return false;
            }
        }

        return true;
    }

    private function value(Node $node, int $level, bool $inline, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('hcl: exceeded max depth (alias cycle?)');
        }

        $node = NodeTools::unwrap($node);
        if (NodeKindEnum::Scalar === $node->kind) {
            return $this->scalar($node);
        }

        if (NodeKindEnum::Sequence === $node->kind) {
            $items = [];
            foreach ($node->content as $item) {
                $items[] = $this->value($item, $level, true, $depth + 1);
            }

            return '[' . implode(', ', $items) . ']';
        }

        $pairs = NodeTools::pairs($node);
        if ([] === $pairs) {
            return '{}';
        }

        if ($inline) {
            $entries = [];
            foreach ($pairs as [$key, $value]) {
                $entries[] = $this->name(NodeTools::keyText($key)) . self::ASSIGN . $this->value($value, $level, true, $depth + 1);
            }

            return '{ ' . implode(', ', $entries) . ' }';
        }

        $inner = str_repeat('  ', $level + 1);
        $out   = "{\n";
        foreach ($pairs as [$key, $value]) {
            $out .= $inner . $this->name(NodeTools::keyText($key)) . self::ASSIGN . $this->value($value, $level + 1, false, $depth + 1) . "\n";
        }

        return $out . str_repeat('  ', $level) . '}';
    }

    private function scalar(Node $node): string
    {
        return match ($node->tag) {
            CoreSchema::TAG_NULL  => 'null',
            CoreSchema::TAG_BOOL  => 'true' === strtolower($node->value) ? 'true' : 'false',
            CoreSchema::TAG_INT   => NodeTools::integerText($node->value) ?? $node->value,
            CoreSchema::TAG_FLOAT => $node->value,
            default               => $node->explicitEnd ? $node->value : $this->quote($node->value),
        };
    }

    private function name(string $key): string
    {
        if (1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $key)) {
            return $key;
        }

        return $this->quote($key);
    }

    private function quote(string $text): string
    {
        return '"' . strtr($text, ['\\' => '\\\\', '"' => '\"', "\n" => '\n', "\r" => '\r', "\t" => '\t']) . '"';
    }

    private function trailing(string ...$comments): string
    {
        return NodeTools::trailingComment(...$comments);
    }

    private function comment(string $comment, string $indent): string
    {
        return NodeTools::hashComment($comment, $indent);
    }
}
