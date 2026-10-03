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
 * Java properties output: one `path = value` line per scalar, array positions as `.0` (or `[0]` with
 * `--properties-array-brackets`), empty collections omitted. Comments on keys and values are written above
 * their property, separated from the previous property by a blank line; head comments of maps and arrays
 * ride along with the first scalar below them.
 */
final class PropsEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 1000;

    public function format(): FormatEnum
    {
        return FormatEnum::Props;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::unwrap($node);
        if (NodeKindEnum::Scalar === $root->kind) {
            return $root->value . "\n";
        }

        $lines = [];
        $this->walk($root, '', '', $options, $lines, 0);

        $out = '';
        foreach ($lines as $position => [$comment, $key, $value]) {
            if ('' !== $comment) {
                $out .= 0 === $position ? '' : "\n";
                $out .= $comment . "\n";
            }

            $out .= $this->escape($key, ' :=') . $options->propertiesSeparator . $this->escape($value, '') . "\n";
        }

        return $out;
    }

    /**
     * @param list<array{string, string, string}> $lines comment block, key, value
     */
    private function walk(Node $node, string $path, string $pending, FormatOptions $options, array &$lines, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('properties: exceeded max depth (alias cycle?)');
        }

        $node = NodeTools::unwrap($node);
        if (NodeKindEnum::Scalar === $node->kind) {
            $lines[] = [$this->commentBlock($pending, $node->headComment, $node->lineComment), $path, $this->value($node, $options)];

            return;
        }

        $pending = $this->join($pending, $node->headComment);
        if (NodeKindEnum::Sequence === $node->kind) {
            foreach ($node->content as $position => $item) {
                $this->walk($item, $this->appendIndex($path, $position, $options), $pending, $options, $lines, $depth + 1);
                $pending = '';
            }

            return;
        }

        foreach (NodeTools::pairs($node) as [$key, $value]) {
            $name         = NodeTools::keyText($key);
            $unwrapped    = NodeTools::unwrap($value);
            $keyComments  = NodeKindEnum::Scalar === $unwrapped->kind ? $this->join($key->headComment, $key->lineComment) : $key->headComment;
            $childPending = $this->join($pending, $keyComments);
            $this->walk($value, '' === $path ? $name : $path . '.' . $name, $childPending, $options, $lines, $depth + 1);
            $pending = '';
        }
    }

    private function appendIndex(string $path, int $position, FormatOptions $options): string
    {
        if ($options->propertiesArrayBrackets && '' !== $path) {
            return $path . '[' . $position . ']';
        }

        return '' === $path ? (string)$position : $path . '.' . $position;
    }

    private function value(Node $node, FormatOptions $options): string
    {
        if (!$options->unwrapScalar && CoreSchema::TAG_STR === $node->tag && ('' === $node->value || 1 === preg_match('/[\s"]/', $node->value))) {
            return '"' . addcslashes($node->value, '"\\') . '"';
        }

        return $node->value;
    }

    private function commentBlock(string ...$comments): string
    {
        $lines = [];
        foreach (explode("\n", $this->join(...$comments)) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $lines[] = str_starts_with($line, '#') ? $line : '# ' . $line;
        }

        return implode("\n", $lines);
    }

    private function join(string ...$comments): string
    {
        return NodeTools::joinComments(...$comments);
    }

    private function escape(string $text, string $special): string
    {
        $out    = '';
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            $out .= match ($char) {
                '\\'    => '\\\\',
                "\f"    => '\f',
                "\n"    => '\n',
                "\r"    => '\r',
                "\t"    => '\t',
                default => '' !== $special && str_contains($special, $char) ? '\\' . $char : $char,
            };
        }

        return $out;
    }
}
