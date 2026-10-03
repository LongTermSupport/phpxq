<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * Slurps the header of a YAML file (the run of comment lines, blank lines and `---` separators before the
 * first content line) so it can be printed back verbatim, ahead of the first result, instead of being
 * re-attached to a node by the parser. Each `---` line becomes {@see self::SEPARATOR_MARKER}.
 *
 * Only a bare `---` (optionally followed by blanks) counts as a separator here; `--- text` and
 * `--- # comment` start the content and are left to the parser.
 */
final class HeaderSplitter
{
    public const string SEPARATOR_MARKER = '$yqDocSeparator$';

    /**
     * @return array{string, string} the header text (newline terminated lines) and the remaining input
     */
    public function split(string $text): array
    {
        $length = \strlen($text);
        $pos    = 0;
        $header = '';
        while ($pos < $length) {
            $newline = strpos($text, "\n", $pos);
            $end     = false === $newline ? $length : $newline + 1;
            $line    = substr($text, $pos, $end - $pos);
            $bare    = rtrim($line, "\r\n");
            $first   = ltrim($bare, " \t");

            if (1 === preg_match('/^---[ \t]*$/', $bare)) {
                $header .= self::SEPARATOR_MARKER . "\n";
            } elseif ('' === $first || '#' === $first[0]) {
                $header .= $bare . "\n";
            } else {
                break;
            }

            $pos = $end;
        }

        return [$header, substr($text, $pos)];
    }
}
