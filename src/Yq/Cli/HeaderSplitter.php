<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * Slurps the header of a YAML file so it can be printed back verbatim ahead of the first result instead of
 * being re-attached to a node by the parser, which cannot reproduce comments written around a `---`.
 *
 * The header is the run of comment lines, blank lines, `%` directives and `---` separators at the top,
 * each separator becoming {@see self::SEPARATOR_MARKER}; the content starts at the first other line.
 *
 * Whole mode (YAML output) takes the entire run: it is the reference's "leading content", which the
 * `head_comment` of the first document's root reads and which an edit that removes the first entry leaves
 * in place. Other output formats take the header only when it holds a separator, or when nothing else
 * follows it (a file holding only comments); comments directly above the content then stay on the content,
 * where the comment-carrying encoders (properties, XML, KYAML) can reach them.
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
    public function split(string $text, bool $whole = true): array
    {
        $length    = \strlen($text);
        $pos       = 0;
        $header    = '';
        $separated = false;
        while ($pos < $length) {
            $newline = strpos($text, "\n", $pos);
            $end     = false === $newline ? $length : $newline + 1;
            $bare    = rtrim(substr($text, $pos, $end - $pos), "\r\n");
            $first   = ltrim($bare, " \t");

            if (1 === preg_match('/^---[ \t]*$/', $bare)) {
                $header .= self::SEPARATOR_MARKER . "\n";
                $separated = true;
            } elseif ('' === $first || '#' === $first[0] || '%' === $first[0]) {
                $header .= $bare . "\n";
            } else {
                return $whole || $separated ? [$header, substr($text, $pos)] : ['', $text];
            }

            $pos = $end;
        }

        return [$header, ''];
    }
}
