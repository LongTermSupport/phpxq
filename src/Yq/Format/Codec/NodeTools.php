<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\FormatException;

/**
 * Small helpers shared by the format codecs: reading through documents, aliases and merge keys, scalar
 * number normalisation and comment text conversion.
 */
final readonly class NodeTools
{
    private const int MAX_ALIAS_DEPTH = 64;

    private function __construct()
    {
    }

    /**
     * The node a codec should look at: a Document's root, an Alias's target, repeated.
     */
    public static function unwrap(Node $node): Node
    {
        for ($depth = 0; $depth < self::MAX_ALIAS_DEPTH; ++$depth) {
            if (NodeKindEnum::Document === $node->kind) {
                $node = $node->content[0] ?? new Node(NodeKindEnum::Scalar, CoreSchema::TAG_NULL);

                continue;
            }

            if (NodeKindEnum::Alias === $node->kind && $node->aliasTarget instanceof Node) {
                $node = $node->aliasTarget;

                continue;
            }

            return $node;
        }

        throw new FormatException('alias chain is too deep');
    }

    public static function isMergeKey(Node $key): bool
    {
        return '<<' === $key->value && NodeKindEnum::Scalar === $key->kind && NodeStyleEnum::Default === $key->style && (CoreSchema::TAG_STR === $key->tag || '!!merge' === $key->tag);
    }

    /**
     * The flat key0, value0, key1, value1, ... list of a mapping with merge keys expanded. A mapping with
     * no merge key returns its own content list, so the common case allocates nothing.
     *
     * @return list<Node>
     */
    public static function flatContent(Node $mapping): array
    {
        $count = \count($mapping->content);
        for ($i = 0; $i < $count; $i += 2) {
            if (self::isMergeKey($mapping->content[$i])) {
                $flat = [];
                foreach (self::pairs($mapping) as [$key, $value]) {
                    $flat[] = $key;
                    $flat[] = $value;
                }

                return $flat;
            }
        }

        return $mapping->content;
    }

    /**
     * The key/value pairs of a mapping with merge keys (`<<: *a`, `<<: [*a, *b]`) expanded in place.
     * An explicit key beats a merged one and an earlier merge source beats a later one.
     *
     * @return list<array{Node, Node}>
     */
    public static function pairs(Node $mapping): array
    {
        $count = \count($mapping->content);
        $plain = true;
        for ($i = 0; $i < $count; $i += 2) {
            if (self::isMergeKey($mapping->content[$i])) {
                $plain = false;

                break;
            }
        }

        $pairs = [];
        if ($plain) {
            for ($i = 0; $i + 1 < $count; $i += 2) {
                $pairs[] = [$mapping->content[$i], $mapping->content[$i + 1]];
            }

            return $pairs;
        }

        $taken = [];
        for ($i = 0; $i + 1 < $count; $i += 2) {
            if (!self::isMergeKey($mapping->content[$i])) {
                $taken[self::identity($mapping->content[$i])] = true;
            }
        }

        for ($i = 0; $i + 1 < $count; $i += 2) {
            $key   = $mapping->content[$i];
            $value = $mapping->content[$i + 1];
            if (!self::isMergeKey($key)) {
                $pairs[] = [$key, $value];

                continue;
            }

            foreach (self::mergeSources($value) as $source) {
                foreach (self::pairs($source) as [$mergedKey, $mergedValue]) {
                    $identity = self::identity($mergedKey);
                    if (isset($taken[$identity])) {
                        continue;
                    }

                    $taken[$identity] = true;
                    $pairs[]          = [$mergedKey, $mergedValue];
                }
            }
        }

        return $pairs;
    }

    /**
     * @throws FormatException when the key is not a scalar
     */
    public static function keyText(Node $key): string
    {
        $key = self::unwrap($key);
        if (NodeKindEnum::Scalar !== $key->kind) {
            throw new FormatException('mapping keys must be scalars for this format');
        }

        return $key->value;
    }

    /**
     * Decimal text of a YAML core-schema integer (decimal, `0x` hex, `0o` octal) with no sign for `+`, no
     * leading zeros, and arbitrary size; null when the text is not an integer.
     */
    public static function integerText(string $text): ?string
    {
        if (1 === preg_match('/^([-+]?)([0-9]+)$/D', $text, $m)) {
            $digits = ltrim($m[2], '0');

            return '' === $digits ? '0' : ('-' === $m[1] ? '-' : '') . $digits;
        }

        if (1 === preg_match('/^0x([0-9a-fA-F]+)$/D', $text, $m)) {
            return self::baseToDecimal(strtolower($m[1]), 16);
        }

        if (1 === preg_match('/^0o([0-7]+)$/D', $text, $m)) {
            return self::baseToDecimal($m[1], 8);
        }

        return null;
    }

    /**
     * The text of a YAML comment without the leading `# ` of each line (`#` alone gives an empty line).
     */
    public static function commentText(string $comment): string
    {
        if ('' === $comment) {
            return '';
        }

        $lines = [];
        foreach (explode("\n", $comment) as $line) {
            $line    = ltrim($line, ' ');
            $line    = str_starts_with($line, '#') ? substr($line, 1) : $line;
            $lines[] = str_starts_with($line, ' ') ? substr($line, 1) : $line;
        }

        return implode("\n", $lines);
    }

    /**
     * The end-of-line comment for the given comments: one ` # ` line with newlines folded to spaces, or
     * nothing when there is no comment text.
     */
    public static function trailingComment(string ...$comments): string
    {
        $text = str_replace("\n", ' ', self::commentText(self::joinDistinct(...$comments)));

        return '' === trim($text) ? '' : ' # ' . $text;
    }

    /**
     * Own-line `#` comment lines at `$indent`, each ending in a newline; a line already starting with `#`
     * is kept as is.
     */
    public static function hashComment(string $comment, string $indent): string
    {
        $comment = rtrim($comment, "\n");
        if ('' === $comment) {
            return '';
        }

        $out = '';
        foreach (explode("\n", $comment) as $line) {
            $line = trim($line);
            $out .= $indent . (str_starts_with($line, '#') ? $line : '# ' . $line) . "\n";
        }

        return $out;
    }

    /**
     * Replaces the child at `$index` of a node's content, keeping the content a list.
     */
    public static function replaceAt(Node $parent, int $index, Node $value): void
    {
        array_splice($parent->content, $index, 1, [$value]);
    }

    /**
     * Joins comments that are present, newline separated.
     */
    public static function joinComments(string ...$comments): string
    {
        return implode("\n", array_filter($comments, static fn (string $comment): bool => '' !== $comment));
    }

    /**
     * Like {@see self::joinComments()} but a comment repeated verbatim (the same node reached twice, for
     * example through an alias) appears once.
     */
    public static function joinDistinct(string ...$comments): string
    {
        return self::joinComments(...array_values(array_unique($comments)));
    }

    /**
     * @return list<Node>
     */
    private static function mergeSources(Node $value): array
    {
        if (NodeKindEnum::Alias === $value->kind) {
            $target = self::unwrap($value);

            return NodeKindEnum::Mapping === $target->kind ? [$target] : [];
        }

        $sources = [];
        if (NodeKindEnum::Sequence === $value->kind) {
            foreach ($value->content as $item) {
                if (NodeKindEnum::Alias !== $item->kind) {
                    continue;
                }

                $item = self::unwrap($item);
                if (NodeKindEnum::Mapping === $item->kind) {
                    $sources[] = $item;
                }
            }
        }

        return $sources;
    }

    private static function identity(Node $key): string
    {
        $key = self::unwrap($key);

        return NodeKindEnum::Scalar === $key->kind ? 's' . $key->value : 'o' . spl_object_id($key);
    }

    private static function baseToDecimal(string $digits, int $base): string
    {
        /** @var list<int> $number little-endian decimal digits */
        $number = [0];
        $length = \strlen($digits);
        for ($i = 0; $i < $length; ++$i) {
            $carry = (int)base_convert($digits[$i], $base, 10);
            foreach ($number as $position => $digit) {
                $total             = $digit * $base + $carry;
                $number[$position] = $total % 10;
                $carry             = intdiv($total, 10);
            }

            while ($carry > 0) {
                $number[] = $carry % 10;
                $carry    = intdiv($carry, 10);
            }
        }

        return implode('', array_reverse($number));
    }
}
