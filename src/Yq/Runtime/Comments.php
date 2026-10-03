<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use LTS\PhpXq\Yaml\Node;

/**
 * Comment text conversion: nodes store comments with their `#` markers, the operators read and write the
 * bare text.
 */
final class Comments
{
    private function __construct()
    {
    }

    /**
     * Strips the `# ` marker from every line.
     */
    public static function read(string $stored): string
    {
        if ('' === $stored) {
            return '';
        }

        $lines = explode("\n", $stored);
        foreach ($lines as $i => $line) {
            if ('' !== $line && '#' === $line[0]) {
                $line = substr($line, 1);
                if ('' !== $line && ' ' === $line[0]) {
                    $line = substr($line, 1);
                }
            }

            $lines[$i] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Puts the `# ` marker in front of every line that lacks one.
     */
    public static function write(string $text): string
    {
        if ('' === $text) {
            return '';
        }

        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ('' === $line) {
                $lines[$i] = '#';
            } elseif ('#' !== $line[0]) {
                $lines[$i] = '# ' . $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param 'head'|'line'|'foot' $kind
     */
    public static function get(Node $node, string $kind): string
    {
        return match ($kind) {
            'head'  => $node->headComment,
            'line'  => $node->lineComment,
            default => $node->footComment,
        };
    }

    /**
     * @param 'head'|'line'|'foot'|'all' $kind
     */
    public static function set(Node $node, string $kind, string $text): void
    {
        $stored = self::write($text);
        if ('head' === $kind || 'all' === $kind) {
            $node->headComment = $stored;
        }

        if ('line' === $kind || 'all' === $kind) {
            $node->lineComment = $stored;
        }

        if ('foot' === $kind || 'all' === $kind) {
            $node->footComment = $stored;
        }
    }
}
