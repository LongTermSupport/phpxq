<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * TOML output. Block mappings become `[table]` sections (a section is skipped when it holds only other
 * tables), flow mappings become inline tables, arrays of block mappings become `[[array]]` sections and
 * every other array is written inline. Scalars come before sub-tables inside a section, a blank line
 * precedes each `[table]`, and comments are written above entries and after values.
 */
final class TomlEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 500;

    public function format(): Format
    {
        return Format::Toml;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::unwrap($node);
        if (NodeKind::Scalar === $root->kind) {
            return $root->value . "\n";
        }

        if (NodeKind::Mapping !== $root->kind) {
            throw new FormatException('toml: only a map can be written as a TOML document');
        }

        $out = '';
        $this->section($out, $root, [], 0);
        $footer = $node->footComment;
        if ('' !== $footer) {
            $out .= ('' === $out ? '' : "\n") . $footer . "\n";
        }

        return $out;
    }

    /**
     * @param list<string> $path
     */
    private function section(string &$out, Node $map, array $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('toml: exceeded max depth (alias cycle?)');
        }

        $tables = [];
        foreach (NodeTools::pairs($map) as [$key, $value]) {
            $resolved = NodeTools::unwrap($value);
            if ($this->isTable($resolved) || $this->isArrayOfTables($resolved)) {
                $tables[] = [$key, $resolved];

                continue;
            }

            $out .= $this->comments($key->headComment);
            $out .= $this->name($key) . ' = ' . $this->inline($resolved, $depth + 1) . $this->trailing($key, $resolved) . "\n";
        }

        foreach ($tables as [$key, $value]) {
            $childPath = [...$path, $this->name($key)];
            if (NodeKind::Mapping === $value->kind) {
                $this->table($out, $key, $value, $childPath, $depth);

                continue;
            }

            foreach ($value->content as $item) {
                $item = NodeTools::unwrap($item);
                $out .= $this->comments($item->headComment);
                $out .= '[[' . implode('.', $childPath) . "]]\n";
                $this->section($out, $item, $childPath, $depth + 1);
            }
        }
    }

    /**
     * @param list<string> $path
     */
    private function table(string &$out, Node $key, Node $map, array $path, int $depth): void
    {
        $pairs      = NodeTools::pairs($map);
        $hasScalars = false;
        foreach ($pairs as [, $value]) {
            $resolved = NodeTools::unwrap($value);
            if (!$this->isTable($resolved) && !$this->isArrayOfTables($resolved)) {
                $hasScalars = true;

                break;
            }
        }

        if ($hasScalars || [] === $pairs) {
            $out .= ('' === $out ? '' : "\n") . $this->comments($key->headComment) . '[' . implode('.', $path) . "]\n";
        }

        $this->section($out, $map, $path, $depth + 1);
    }

    private function isTable(Node $node): bool
    {
        return NodeKind::Mapping === $node->kind && NodeStyle::Flow !== $node->style && !$node->explicitStart;
    }

    private function isArrayOfTables(Node $node): bool
    {
        if (NodeKind::Sequence !== $node->kind || [] === $node->content || NodeStyle::Flow === $node->style) {
            return false;
        }

        foreach ($node->content as $item) {
            if (!$this->isTable(NodeTools::unwrap($item))) {
                return false;
            }
        }

        return true;
    }

    private function inline(Node $node, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('toml: exceeded max depth (alias cycle?)');
        }

        $node = NodeTools::unwrap($node);
        if (NodeKind::Sequence === $node->kind) {
            $items = [];
            foreach ($node->content as $item) {
                $items[] = $this->inline($item, $depth + 1);
            }

            return '[' . implode(', ', $items) . ']';
        }

        if (NodeKind::Mapping === $node->kind) {
            $entries = [];
            foreach (NodeTools::pairs($node) as [$key, $value]) {
                $entries[] = $this->name($key) . ' = ' . $this->inline($value, $depth + 1);
            }

            return [] === $entries ? '{}' : '{ ' . implode(', ', $entries) . ' }';
        }

        return $this->scalar($node);
    }

    private function scalar(Node $node): string
    {
        switch ($node->tag) {
            case CoreSchema::TAG_INT:
                return $node->value;
            case CoreSchema::TAG_FLOAT:
                return match ($node->value) {
                    '.inf', '.Inf', '.INF', '+.inf' => 'inf',
                    '-.inf', '-.Inf', '-.INF'       => '-inf',
                    '.nan', '.NaN', '.NAN'          => 'nan',
                    default                         => $node->value,
                };
            case CoreSchema::TAG_BOOL:
                return 'true' === strtolower($node->value) ? 'true' : 'false';
            case CoreSchema::TAG_TIMESTAMP:
                return $node->value;
            case CoreSchema::TAG_NULL:
                return '""';
            default:
                return $this->quote($node->value);
        }
    }

    private function name(Node $key): string
    {
        $text = NodeTools::keyText($key);
        if ('' !== $text && \strlen($text) === strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-')) {
            return $text;
        }

        return $this->quote($text);
    }

    private function quote(string $text): string
    {
        $escaped = strtr($text, [
            '\\'   => '\\\\',
            '"'    => '\\"',
            "\n"   => '\\n',
            "\t"   => '\\t',
            "\r"   => '\\r',
            "\x08" => '\\b',
            "\x0c" => '\\f',
        ]);
        $escaped = preg_replace_callback('/[\x00-\x1f\x7f]/', static fn (array $m): string => \sprintf('\\u%04X', \ord($m[0])), $escaped) ?? $escaped;

        return '"' . $escaped . '"';
    }

    private function trailing(Node $key, Node $value): string
    {
        $comment = NodeTools::joinComments($key->lineComment, $value->lineComment);
        if ('' === $comment) {
            return '';
        }

        return '  ' . str_replace("\n", ' ', $comment);
    }

    private function comments(string $comment): string
    {
        $comment = rtrim($comment, "\n");
        if ('' === $comment) {
            return '';
        }

        $lines = [];
        foreach (explode("\n", $comment) as $line) {
            $line    = trim($line);
            $lines[] = str_starts_with($line, '#') ? $line : '# ' . $line;
        }

        return implode("\n", $lines) . "\n";
    }
}
