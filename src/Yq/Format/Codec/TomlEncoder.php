<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * TOML output. Block mappings become `[table]` sections (a section is skipped when it holds only other
 * tables), flow mappings become inline tables, arrays of block mappings become `[[array]]` sections and
 * every other array is written inline. Scalars come before sub-tables inside a section, a blank line
 * precedes each `[table]`, and comments are written above entries and after values.
 *
 * @internal
 */
final readonly class TomlEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 500;

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::expandableRoot($node, $options->yamlFixMergeAnchorToSpec);
        if (NodeKindEnum::Scalar === $root->kind) {
            return $root->value . "\n";
        }

        if (NodeKindEnum::Mapping !== $root->kind) {
            throw new FormatException('toml: only a map can be written as a TOML document');
        }

        $out = '';
        $this->section($out, $root, $options->yamlFixMergeAnchorToSpec, [], 0);
        $footer = $node->footComment;
        if ('' !== $footer) {
            $out .= ('' === $out ? '' : "\n") . $footer . "\n";
        }

        return $out;
    }

    /**
     * @param list<string> $path
     * @param bool         $inItem whether `$map` is an item of an array of tables, which keeps nested
     *                             `[[arrays]]` snug against its header without a blank line
     */
    private function section(string &$out, Node $map, bool $fixedMerge, array $path, int $depth, bool $inItem = false): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('toml: exceeded max depth (alias cycle?)');
        }

        $tables = [];
        foreach (NodeTools::pairs($map, $fixedMerge) as [$key, $value]) {
            $resolved = NodeTools::unwrap($value);
            if ($this->isTable($resolved) || $this->isArrayOfTables($resolved)) {
                $tables[] = [$key, $resolved];

                continue;
            }

            $out .= $this->comments($key->headComment);
            $out .= $this->name($key) . ' = ' . $this->inline($resolved, $fixedMerge, $depth + 1) . $this->trailing($key, $resolved) . "\n";
        }

        foreach ($tables as [$key, $value]) {
            $childPath = [...$path, $this->name($key)];
            if (NodeKindEnum::Mapping === $value->kind) {
                $this->table($out, $key, $value, $fixedMerge, $depth, ...$childPath);

                continue;
            }

            foreach ($value->content as $position => $item) {
                $item = NodeTools::unwrap($item);
                if (0 === $position && !$inItem && '' !== $out) {
                    $out .= "\n";
                }

                $out .= $this->comments($item->headComment);
                $out .= '[[' . implode('.', $childPath) . "]]\n";
                $this->section($out, $item, $fixedMerge, $childPath, $depth + 1, true);
            }
        }
    }

    private function table(string &$out, Node $key, Node $map, bool $fixedMerge, int $depth, string ...$path): void
    {
        $pairs      = NodeTools::pairs($map, $fixedMerge);
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

        $this->section($out, $map, $fixedMerge, array_values($path), $depth + 1);
    }

    private function isTable(Node $node): bool
    {
        return NodeKindEnum::Mapping === $node->kind && NodeStyleEnum::Flow !== $node->style && !$node->explicitStart;
    }

    private function isArrayOfTables(Node $node): bool
    {
        if (NodeKindEnum::Sequence !== $node->kind || [] === $node->content || NodeStyleEnum::Flow === $node->style) {
            return false;
        }

        return array_all($node->content, fn (Node $item): bool => $this->isTable(NodeTools::unwrap($item)));
    }

    private function inline(Node $node, bool $fixedMerge, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('toml: exceeded max depth (alias cycle?)');
        }

        $node = NodeTools::unwrap($node);
        if (NodeKindEnum::Sequence === $node->kind) {
            $items = [];
            foreach ($node->content as $item) {
                $items[] = $this->inline($item, $fixedMerge, $depth + 1);
            }

            return '[' . implode(', ', $items) . ']';
        }

        if (NodeKindEnum::Mapping === $node->kind) {
            $entries = [];
            foreach (NodeTools::pairs($node, $fixedMerge) as [$key, $value]) {
                $entries[] = $this->name($key) . ' = ' . $this->inline($value, $fixedMerge, $depth + 1);
            }

            return [] === $entries ? '{}' : '{ ' . implode(', ', $entries) . ' }';
        }

        return $this->scalar($node);
    }

    private function scalar(Node $node): string
    {
        return match ($node->tag) {
            CoreSchema::TAG_INT       => $node->value,
            CoreSchema::TAG_FLOAT     => match ($node->value) {
                '.inf', '.Inf', '.INF', '+.inf' => 'inf',
                '-.inf', '-.Inf', '-.INF'       => '-inf',
                '.nan', '.NaN', '.NAN'          => 'nan',
                default                         => $node->value,
            },
            CoreSchema::TAG_BOOL      => 'true' === strtolower($node->value) ? 'true' : 'false',
            CoreSchema::TAG_TIMESTAMP => $node->value,
            CoreSchema::TAG_NULL      => '""',
            default                   => $this->quote($node->value),
        };
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
            '"'    => '\"',
            "\n"   => '\n',
            "\t"   => '\t',
            "\r"   => '\r',
            "\x08" => '\b',
            "\x0c" => '\f',
        ]);
        $escaped = preg_replace_callback('/[\x00-\x1f\x7f]/', static fn (array $m): string => \sprintf('\u%04X', \ord($m[0])), $escaped) ?? $escaped;

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
