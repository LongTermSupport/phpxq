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
 * Shell variable output: one `name=value` line per scalar, nested keys joined with the key separator
 * (`_` by default) and array positions as numbers. Names keep ASCII letters, digits and `_`; accents are
 * folded to their base letter, other printable ASCII becomes `_`, everything else is dropped. Values are
 * single-quoted when they hold anything but safe characters, null is empty, and empty maps and arrays
 * produce nothing.
 */
final class ShellEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 1000;

    /** @var array<string, string>|null */
    private static ?array $folding = null;

    public function format(): FormatEnum
    {
        return FormatEnum::Shell;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::expandableRoot($node, $options->yamlFixMergeAnchorToSpec);
        if (NodeKindEnum::Scalar === $root->kind) {
            return $this->value($root) . "\n";
        }

        $out = '';
        $this->walk($out, $root, $options, 0);

        return $out;
    }

    private function walk(string &$out, Node $node, FormatOptions $options, int $depth, string ...$parts): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('shell: exceeded max depth (alias cycle?)');
        }

        $node = NodeTools::unwrap($node);
        if (NodeKindEnum::Scalar === $node->kind) {
            $out .= implode($options->shellKeySeparator, $parts) . '=' . $this->value($node) . "\n";

            return;
        }

        if (NodeKindEnum::Sequence === $node->kind) {
            foreach ($node->content as $position => $item) {
                $this->walk($out, $item, $options, $depth + 1, ...[...$parts, (string)$position]);
            }

            return;
        }

        foreach (NodeTools::pairs($node, $options->yamlFixMergeAnchorToSpec) as [$key, $value]) {
            $this->walk($out, $value, $options, $depth + 1, ...[...$parts, $this->name(NodeTools::keyText($key))]);
        }
    }

    private function value(Node $node): string
    {
        return CoreSchema::TAG_NULL === $node->tag ? '' : StringFormats::shellQuote($node->value);
    }

    private function name(string $key): string
    {
        $key = strtr($key, $this->folding());
        $key = preg_replace('/[^\x20-\x7e]+/', '', $key) ?? $key;

        return preg_replace('/[^A-Za-z0-9_]/', '_', $key) ?? $key;
    }

    /**
     * @return array<string, string> accented Latin letters mapped to their base letter
     */
    private function folding(): array
    {
        if (null !== self::$folding) {
            return self::$folding;
        }

        $groups = [
            'A' => 'ÀÁÂÃÄÅĀĂĄǍ', 'a' => 'àáâãäåāăąǎ', 'C' => 'ÇĆĈĊČ', 'c' => 'çćĉċč', 'D' => 'ĎĐ', 'd' => 'ďđ',
            'E' => 'ÈÉÊËĒĔĖĘĚ', 'e' => 'èéêëēĕėęě', 'G' => 'ĜĞĠĢ', 'g' => 'ĝğġģ', 'H' => 'ĤĦ', 'h' => 'ĥħ',
            'I' => 'ÌÍÎÏĨĪĬĮİǏ', 'i' => 'ìíîïĩīĭįıǐ', 'J' => 'Ĵ', 'j' => 'ĵ', 'K' => 'Ķ', 'k' => 'ķ',
            'L' => 'ĹĻĽĿŁ', 'l' => 'ĺļľŀł', 'N' => 'ÑŃŅŇ', 'n' => 'ñńņň', 'O' => 'ÒÓÔÕÖØŌŎŐǑ', 'o' => 'òóôõöøōŏőǒ',
            'R' => 'ŔŖŘ', 'r' => 'ŕŗř', 'S' => 'ŚŜŞŠ', 's' => 'śŝşš', 'T' => 'ŢŤŦ', 't' => 'ţťŧ',
            'U' => 'ÙÚÛÜŨŪŬŮŰŲǓ', 'u' => 'ùúûüũūŭůűųǔ', 'W' => 'Ŵ', 'w' => 'ŵ', 'Y' => 'ÝŶŸ', 'y' => 'ýÿŷ',
            'Z' => 'ŹŻŽ', 'z' => 'źżž',
        ];

        $map = [];
        foreach ($groups as $base => $accented) {
            foreach (mb_str_split($accented) as $char) {
                $map[$char] = $base;
            }
        }

        // Combining marks left behind by decomposed input.
        for ($code = 0x300; $code <= 0x36F; ++$code) {
            $map[mb_chr($code, 'UTF-8')] = '';
        }

        return self::$folding = $map;
    }
}
