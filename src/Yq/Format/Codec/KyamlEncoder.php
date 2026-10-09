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
 * KYAML output: a flow-style YAML subset with every collection on its own lines, trailing commas, bare
 * keys where safe, double-quoted strings, and comments kept (line comments after the comma, head and foot
 * comments on their own lines). Aliases and merge keys are expanded. A leading comment of the document is
 * written above the first brace.
 *
 * @internal
 */
final readonly class KyamlEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 1000;

    public function format(): FormatEnum
    {
        return FormatEnum::Kyaml;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root   = NodeTools::expandableRoot($node, $options->yamlFixMergeAnchorToSpec);
        $header = $node->headComment;
        if ('' === $header && NodeKindEnum::Mapping === $root->kind && [] !== $root->content) {
            $header                = $root->content[0]->headComment;
            $firstKey              = clone $root->content[0];
            $firstKey->headComment = '';
            $root                  = clone $root;
            $root->content         = [$firstKey, ...\array_slice($root->content, 1)];
        }

        $out = $this->comment($header, '');
        $out .= $this->value($root, 0, $options) . "\n";

        return $out . $this->comment($node->footComment, '');
    }

    private function value(Node $node, int $depth, FormatOptions $options): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('kyaml: exceeded max depth (alias cycle?)');
        }

        $node = NodeTools::unwrap($node);
        if (NodeKindEnum::Scalar === $node->kind) {
            return $this->scalar($node);
        }

        $inner = str_repeat('  ', $depth + 1);
        $close = str_repeat('  ', $depth);
        if (NodeKindEnum::Sequence === $node->kind) {
            if ([] === $node->content) {
                return '[]';
            }

            $out = "[\n";
            foreach ($node->content as $item) {
                $resolved = NodeTools::unwrap($item);
                $out     .= $this->comment($item->headComment, $inner);
                $out     .= $inner . $this->value($item, $depth + 1, $options) . ',' . $this->trailing($item->lineComment, $resolved->lineComment) . "\n";
                $out     .= $this->comment($item->footComment, $inner);
            }

            return $out . $close . ']';
        }

        $pairs = NodeTools::pairs($node, $options->yamlFixMergeAnchorToSpec);
        if ([] === $pairs) {
            return '{}';
        }

        $out = "{\n";
        foreach ($pairs as [$key, $value]) {
            $resolved = NodeTools::unwrap($value);
            $out     .= $this->comment($key->headComment, $inner);
            $out     .= $inner . $this->key($key) . ': ' . $this->value($value, $depth + 1, $options) . ',';
            $out     .= $this->trailing($key->lineComment, $value->lineComment, NodeKindEnum::Scalar === $resolved->kind ? $resolved->lineComment : '') . "\n";
            $out     .= $this->comment($key->footComment, $inner);
        }

        return $out . $close . '}';
    }

    private function key(Node $key): string
    {
        $text = NodeTools::keyText($key);
        if (1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $text) && CoreSchema::TAG_STR === CoreSchema::resolve($text)) {
            return $text;
        }

        return $this->quote($text);
    }

    private function scalar(Node $node): string
    {
        return match ($node->tag) {
            CoreSchema::TAG_NULL                       => 'null',
            CoreSchema::TAG_BOOL                       => 'true' === strtolower($node->value) ? 'true' : 'false',
            CoreSchema::TAG_INT, CoreSchema::TAG_FLOAT => $node->value,
            default                                    => $this->quote($node->value),
        };
    }

    private function quote(string $text): string
    {
        $escaped = strtr($text, [
            '\\'   => '\\\\',
            '"'    => '\"',
            "\n"   => '\n',
            "\r"   => '\r',
            "\t"   => '\t',
            "\x08" => '\b',
            "\x0c" => '\f',
        ]);
        $escaped = preg_replace_callback('/[\x00-\x1f\x7f]/', static fn (array $m): string => \sprintf('\u%04x', \ord($m[0])), $escaped) ?? $escaped;

        return '"' . $escaped . '"';
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
