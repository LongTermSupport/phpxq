<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * Java properties input. Keys are split on `.`; an all-digit segment indexes an array (missing positions
 * are filled with nulls), anything else is a map key. Every value is a string. Comment lines above a
 * property become its head comment.
 */
final readonly class PropsDecoder implements DecoderInterface
{
    private const string BLANKS = " \t\f";

    public function format(): FormatEnum
    {
        return FormatEnum::Props;
    }

    /**
     * @return iterable<Node>
     */
    public function decode(string $input, FormatOptions $options): iterable
    {
        if ('' === trim($input)) {
            return;
        }

        $root  = Node::mapping();
        $index = [];
        foreach ($this->entries($input) as [$key, $value, $comment]) {
            $parts = [];
            foreach (explode('.', $key) as $part) {
                $parts[] = 1 === preg_match('/^[0-9]{1,9}$/D', $part) ? (int)$part : $part;
            }

            $this->assign($root, Node::scalar($value, CoreSchema::TAG_STR), $comment, $index, ...$parts);
        }

        yield Node::document($root);
    }

    /**
     * @return list<array{string, string, string}> key, value, comment
     */
    private function entries(string $input): array
    {
        $lines    = preg_split('/\r\n|\n|\r/', $input);
        if (false === $lines) {
            $lines = [$input];
        }

        $count    = \count($lines);
        $entries  = [];
        $comments = [];
        for ($i = 0; $i < $count;) {
            $line = ltrim($lines[$i++], self::BLANKS);
            if ('' === $line) {
                continue;
            }

            if ('#' === $line[0] || '!' === $line[0]) {
                $text       = ltrim(substr($line, 1), self::BLANKS);
                $comments[] = '' === $text ? '#' : '# ' . $text;

                continue;
            }

            while ($this->endsWithContinuation($line)) {
                $line = substr($line, 0, -1);
                if ($i < $count) {
                    $line .= ltrim($lines[$i++], self::BLANKS);
                }
            }

            [$key, $value] = $this->split($line);
            $entries[]     = [$this->unescape($key), $this->unescape($value), implode("\n", $comments)];
            $comments      = [];
        }

        return $entries;
    }

    private function endsWithContinuation(string $line): bool
    {
        $length  = \strlen($line);
        $slashes = 0;
        while ($slashes < $length && '\\' === $line[$length - 1 - $slashes]) {
            ++$slashes;
        }

        return 1 === $slashes % 2;
    }

    /**
     * @return array{string, string}
     */
    private function split(string $line): array
    {
        $length = \strlen($line);
        $i      = 0;
        while ($i < $length) {
            $char = $line[$i];
            if ('\\' === $char) {
                $i += 2;

                continue;
            }

            if ('=' === $char || ':' === $char || str_contains(self::BLANKS, $char)) {
                break;
            }

            ++$i;
        }

        $i     = min($i, $length);
        $key   = substr($line, 0, $i);
        $i    += strspn($line, self::BLANKS, $i);
        if ($i < $length && ('=' === $line[$i] || ':' === $line[$i])) {
            ++$i;
            $i += strspn($line, self::BLANKS, $i);
        }

        return [$key, substr($line, $i)];
    }

    private function unescape(string $text): string
    {
        if (!str_contains($text, '\\')) {
            return $text;
        }

        $out    = '';
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            if ('\\' !== $char) {
                $out .= $char;

                continue;
            }

            if (++$i >= $length) {
                break;
            }

            $escaped = $text[$i];
            if ('u' === $escaped) {
                $code = $this->hex($text, $i + 1);
                $i += 4;
                if ($code >= 0xD800 && $code <= 0xDBFF && '\u' === substr($text, $i + 1, 2)) {
                    $low = $this->hex($text, $i + 3);
                    if ($low >= 0xDC00 && $low <= 0xDFFF) {
                        $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
                        $i += 6;
                    }
                }

                $out .= $code >= 0xD800 && $code <= 0xDFFF ? "\u{FFFD}" : mb_chr($code, 'UTF-8');

                continue;
            }

            $out .= match ($escaped) {
                't'     => "\t",
                'n'     => "\n",
                'r'     => "\r",
                'f'     => "\f",
                default => $escaped,
            };
        }

        return $out;
    }

    private function hex(string $text, int $offset): int
    {
        $digits = substr($text, $offset, 4);
        if (4 !== \strlen($digits) || 4 !== strspn($digits, '0123456789abcdefABCDEF')) {
            throw new FormatException('properties: malformed \uxxxx encoding');
        }

        return (int)hexdec($digits);
    }

    /**
     * @param array<int, array<string, int>> $index key positions per mapping, by object id
     */
    private function assign(Node $root, Node $leaf, string $comment, array &$index, int|string ...$parts): void
    {
        $node  = $root;
        $parts = array_values($parts);
        $last  = \count($parts) - 1;
        foreach ($parts as $position => $part) {
            if (NodeKindEnum::Sequence === $node->kind) {
                if (!\is_int($part)) {
                    throw new FormatException('properties: cannot use "' . $part . '" as an array index');
                }

                while (\count($node->content) <= $part) {
                    $node->content[] = Node::scalar('null', CoreSchema::TAG_NULL);
                }

                if ($position === $last) {
                    $leaf->headComment = $comment;
                    NodeTools::replaceAt($node, $part, $leaf);

                    return;
                }

                $child = $this->container($node->content[$part], $parts[$position + 1]);
                if ($child !== $node->content[$part]) {
                    NodeTools::replaceAt($node, $part, $child);
                }

                $node = $child;

                continue;
            }

            $text = (string)$part;
            $id   = spl_object_id($node);
            if (!isset($index[$id])) {
                $index[$id] = [];
                $counter    = \count($node->content);
                for ($i = 0; $i + 1 < $counter; $i += 2) {
                    $index[$id][$node->content[$i]->value] = $i;
                }
            }

            $at = $index[$id][$text] ?? null;
            if ($position === $last) {
                $key = null === $at ? Node::scalar($text, CoreSchema::TAG_STR) : $node->content[$at];
                if ('' !== $comment) {
                    $key->headComment = $comment;
                }

                if (null === $at) {
                    $index[$id][$text] = \count($node->content);
                    $node->content[]   = $key;
                    $node->content[]   = $leaf;
                } else {
                    NodeTools::replaceAt($node, $at + 1, $leaf);
                }

                return;
            }

            if (null === $at) {
                $child             = $this->container(null, $parts[$position + 1]);
                $index[$id][$text] = \count($node->content);
                $node->content[]   = Node::scalar($text, CoreSchema::TAG_STR);
                $node->content[]   = $child;
            } else {
                $child = $this->container($node->content[$at + 1], $parts[$position + 1]);
                if ($child !== $node->content[$at + 1]) {
                    NodeTools::replaceAt($node, $at + 1, $child);
                }
            }

            $node = $child;
        }
    }

    /**
     * The existing node when it can hold the next path segment, otherwise a fresh array or map.
     */
    private function container(?Node $existing, int|string $next): Node
    {
        if ($existing instanceof Node) {
            if (NodeKindEnum::Mapping === $existing->kind || (NodeKindEnum::Sequence === $existing->kind && \is_int($next))) {
                return $existing;
            }
        }

        return \is_int($next) ? Node::sequence() : Node::mapping();
    }
}
