<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * Splits a file with YAML front matter into the YAML part and the remaining content.
 *
 * The YAML part starts at the first line (a leading `---` line belongs to it) and ends before the next
 * line starting with `---`; that line and everything after it is the content.
 *
 * @internal
 */
final readonly class FrontMatterSplitter
{
    /**
     * @return array{string, string} the YAML part and the content
     */
    public function split(string $text): array
    {
        $length = \strlen($text);
        $pos    = 0;
        $lineNo = 0;
        while ($pos < $length) {
            $newline = strpos($text, "\n", $pos);
            $end     = false === $newline ? $length : $newline + 1;
            ++$lineNo;
            if ($lineNo > 1 && str_starts_with(substr($text, $pos, 3), '---')) {
                return [substr($text, 0, $pos), substr($text, $pos)];
            }

            $pos = $end;
        }

        return [$text, ''];
    }
}
