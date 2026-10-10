<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * Lua output: `return { ... };` with tab indentation, map entries as `["key"] = value;` (bare `key = value;`
 * with `--lua-unquoted`), array items as `value,`, and with `--lua-globals` the top-level entries as
 * global assignments. Comments become Lua `--` comments. Infinity and NaN are written as `(1/0)`,
 * `(-1/0)` and `(0/0)`.
 *
 * @internal
 */
final readonly class LuaEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 1000;

    private const array KEYWORDS = [
        'and', 'break', 'do', 'else', 'elseif', 'end', 'false', 'for', 'function', 'goto', 'if', 'in', 'local',
        'nil', 'not', 'or', 'repeat', 'return', 'then', 'true', 'until', 'while',
    ];

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::expandableRoot($node, $options->yamlFixMergeAnchorToSpec);
        if ($options->luaGlobals && NodeKindEnum::Mapping === $root->kind) {
            $out = '';
            foreach (NodeTools::pairs($root, $options->yamlFixMergeAnchorToSpec) as [$key, $value]) {
                $out .= $this->entry($key, $value, 0, $options->luaUnquoted, $options, true);
            }

            return $out;
        }

        return 'return ' . $this->value($root, 0, $options) . ";\n";
    }

    private function value(Node $node, int $depth, FormatOptions $options): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('lua: exceeded max depth (alias cycle?)');
        }

        $node = NodeTools::unwrap($node);
        if (NodeKindEnum::Scalar === $node->kind) {
            return $this->scalar($node);
        }

        $inner = str_repeat("\t", $depth + 1);
        if (NodeKindEnum::Sequence === $node->kind) {
            if ([] === $node->content) {
                return '{}';
            }

            $out = "{\n";
            foreach ($node->content as $item) {
                $resolved = NodeTools::unwrap($item);
                $out     .= $this->comment($item->headComment, $inner);
                $out     .= $inner . $this->value($item, $depth + 1, $options) . ',' . $this->trailing($item->lineComment, $resolved->lineComment) . "\n";
            }

            return $out . str_repeat("\t", $depth) . '}';
        }

        $pairs = NodeTools::pairs($node, $options->yamlFixMergeAnchorToSpec);
        if ([] === $pairs) {
            return '{}';
        }

        $out = "{\n";
        foreach ($pairs as [$key, $value]) {
            $out .= $this->entry($key, $value, $depth + 1, $options->luaUnquoted, $options, false);
        }

        return $out . str_repeat("\t", $depth) . '}';
    }

    private function entry(Node $key, Node $value, int $depth, bool $unquoted, FormatOptions $options, bool $global): string
    {
        $indent   = str_repeat("\t", $depth);
        $resolved = NodeTools::unwrap($value);
        $name     = $this->keyText($key, $depth, $unquoted || $global, $options);

        return $this->comment($key->headComment, $indent)
            . $indent . $name . ' = ' . $this->value($value, $depth, $options) . ';'
            . $this->trailing($key->lineComment, $resolved->lineComment, $value->lineComment) . "\n";
    }

    private function keyText(Node $key, int $depth, bool $unquoted, FormatOptions $options): string
    {
        $resolved = NodeTools::unwrap($key);
        if (NodeKindEnum::Scalar !== $resolved->kind) {
            return '[' . $this->value($resolved, $depth, $options) . ']';
        }

        if ($unquoted && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $resolved->value) && !\in_array($resolved->value, self::KEYWORDS, true)) {
            return $resolved->value;
        }

        if (CoreSchema::TAG_INT === $resolved->tag || CoreSchema::TAG_FLOAT === $resolved->tag) {
            return '[' . $this->scalar($resolved) . ']';
        }

        return '[' . $this->quote($resolved->value) . ']';
    }

    private function scalar(Node $node): string
    {
        switch ($node->tag) {
            case CoreSchema::TAG_NULL:
                return 'nil';
            case CoreSchema::TAG_BOOL:
                return 'true' === strtolower($node->value) ? 'true' : 'false';
            case CoreSchema::TAG_INT:
                if (1 === preg_match('/^0x[0-9a-fA-F]+$/D', $node->value)) {
                    return $node->value;
                }

                return NodeTools::integerText($node->value) ?? $this->quote($node->value);
            case CoreSchema::TAG_FLOAT:
                return match ($node->value) {
                    '.inf', '.Inf', '.INF', '+.inf', '+.Inf', '+.INF' => '(1/0)',
                    '-.inf', '-.Inf', '-.INF'                         => '(-1/0)',
                    '.nan', '.NaN', '.NAN'                            => '(0/0)',
                    default                                           => ltrim($node->value, '+'),
                };
            default:
                return $this->quote($node->value);
        }
    }

    private function quote(string $text): string
    {
        $escaped = strtr($text, [
            '\\'   => '\\\\',
            '"'    => '\"',
            "\n"   => '\n',
            "\r"   => '\r',
            "\t"   => '\t',
            "\x07" => '\a',
            "\x08" => '\b',
            "\x0c" => '\f',
            "\x0b" => '\v',
        ]);
        $escaped = preg_replace_callback('/[\x00-\x1f\x7f]/', static fn (array $m): string => \sprintf('\%03d', \ord($m[0])), $escaped) ?? $escaped;

        return '"' . $escaped . '"';
    }

    private function trailing(string ...$comments): string
    {
        $text = str_replace("\n", ' ', NodeTools::commentText(NodeTools::joinDistinct(...$comments)));

        return '' === trim($text) ? '' : ' -- ' . $text;
    }

    private function comment(string $comment, string $indent): string
    {
        $text = NodeTools::commentText(rtrim($comment, "\n"));
        if ('' === $text) {
            return '';
        }

        $out = '';
        foreach (explode("\n", $text) as $line) {
            $out .= $indent . ('' === $line ? '--' : '-- ' . $line) . "\n";
        }

        return $out;
    }
}
