<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Parser;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;

/**
 * A single-pass parser for the plain subset of block YAML that most data files use: one document of
 * block mappings and block sequences whose scalars are plain, single-line, printable ASCII and carry no
 * comments, anchors, tags or quoting. It builds the same Node tree, positions included, that
 * {@see StreamParser} builds, without tokens, and returns null for anything outside the subset (or
 * anything it is not certain about) so the caller can fall back to the full parser. It never reports a
 * syntax error itself.
 *
 * Why it exists: the token pipeline costs several microseconds per token in PHP, and a parse of a
 * 600 KB file was most of the time of every yq run (benchmark yq:identity-medium). The subset is
 * deliberately conservative; each rule below names what it keeps out:
 *  - a scalar starts with a letter, digit or one of `_ . / + ~ $ ( ) = < ^ ;`, or with `-` followed by a visible character;
 *  - a scalar has no `#` (comments) and no colon followed by a blank or the line end (mapping syntax);
 *  - a line holds only spaces (never tabs) and ASCII (columns are then byte offsets), with LF line ends;
 *  - a value line may not be followed by a more indented line (a plain scalar would continue there);
 *  - a line starting at column 0 with `---` or `...` is a document marker, so it is declined.
 */
final class FastBlockParser
{
    /** First character of a scalar. */
    private const string FIRST = '(?:[A-Za-z0-9_.\/+~$()=<^;]|-(?=[\x21-\x7E]))';

    /** A visible ASCII character other than `#` and `:`. */
    private const string REST = '[\x21\x22\x24-\x39\x3B-\x7E]';

    /** A mapping key: no colon and no `#`; blanks only between visible characters. */
    private const string KEY = self::FIRST . '(?:' . self::REST . '|[ ]++(?=' . self::REST . '))*+';

    /** A scalar value: a colon only before a visible character; blanks only between visible characters. */
    private const string VALUE = self::FIRST . '(?:' . self::REST . '|:(?=[\x21-\x7E])|[ ]++(?=[\x21-\x7E]))*+';

    /**
     * Groups: 1 blank lines before, 2 indent, 3 dash, 4 blanks after the dash, 5 key, 6 blanks after the
     * colon, 7 value after a key, 8 value of a sequence item.
     */
    private const string LINE = '/\G((?: *\n)*)(?!---|\.\.\.)( *)(?:(-)( +))?(?:(' . self::KEY . '):(?:( +)(' . self::VALUE . '))?(?=\n)|(' . self::VALUE . '))\n/';

    private function __construct()
    {
    }

    /**
     * @return Node|null the Document node, or null when the text is outside the subset
     */
    public static function parse(string $yaml): ?Node
    {
        $length = \strlen($yaml);
        if ($length < 2) {
            return null;
        }

        if ("\n" !== $yaml[$length - 1]) {
            $yaml .= "\n";
            ++$length;
        }

        /** @var list<int> $indents indent column of each open collection */
        $indents = [];
        /** @var list<bool> $isSequence whether each open collection is a sequence */
        $isSequence = [];
        /** @var list<Node> $nodes */
        $nodes = [];
        /** @var list<bool> $indentless whether a sequence sits at the indent of the mapping that owns it */
        $indentless = [];
        $depth      = -1;

        $pending       = null;
        $pendingIndent = 0;
        $pendingLine   = 0;
        $pendingColumn = 0;
        $root          = null;
        $rootLine      = 0;
        $position      = 0;
        $line          = 1;

        while ($position < $length) {
            if (1 !== preg_match(self::LINE, $yaml, $m, \PREG_UNMATCHED_AS_NULL, $position)) {
                if (null === $root || strspn($yaml, " \n", $position) !== $length - $position) {
                    return null;
                }

                break;
            }

            $position += \strlen($m[0] ?? '');
            $blank = $m[1] ?? '';
            if ('' !== $blank) {
                $line += substr_count($blank, "\n");
            }

            $indent    = \strlen($m[2] ?? '');
            $dash      = null !== ($m[3] ?? null);
            $key       = $m[5] ?? null;
            $valueOnly = $m[8] ?? null;
            if (!$dash && null === $key) {
                return null;
            }

            // a key line that opened a nested block or left its value null on the previous line
            if (null !== $pending) {
                if ($indent > $pendingIndent) {
                    $child = $dash
                        ? new Node(NodeKindEnum::Sequence, CoreSchema::TAG_SEQ, NodeStyleEnum::Default, line: $line, column: $indent + 1)
                        : new Node(NodeKindEnum::Mapping, CoreSchema::TAG_MAP, NodeStyleEnum::Default, line: $line, column: $indent + 1);
                    $pending->content[] = $child;
                    $indents[]          = $indent;
                    $isSequence[]       = $dash;
                    $nodes[]            = $child;
                    $indentless[]       = false;
                    ++$depth;
                } elseif ($indent === $pendingIndent && $dash && !$isSequence[$depth]) {
                    $child              = new Node(NodeKindEnum::Sequence, CoreSchema::TAG_SEQ, NodeStyleEnum::Default, line: $line, column: $indent + 1);
                    $pending->content[] = $child;
                    $indents[]          = $indent;
                    $isSequence[]       = true;
                    $nodes[]            = $child;
                    $indentless[]       = true;
                    ++$depth;
                } else {
                    $pending->content[] = new Node(NodeKindEnum::Scalar, CoreSchema::TAG_NULL, NodeStyleEnum::Default, line: $pendingLine, column: $pendingColumn);
                }

                $pending = null;
            }

            if ($depth < 0) {
                if (0 !== $indent) {
                    return null;
                }

                $root         = $dash
                    ? new Node(NodeKindEnum::Sequence, CoreSchema::TAG_SEQ, NodeStyleEnum::Default, line: $line, column: 1)
                    : new Node(NodeKindEnum::Mapping, CoreSchema::TAG_MAP, NodeStyleEnum::Default, line: $line, column: 1);
                $rootLine     = $line;
                $indents[]    = 0;
                $isSequence[] = $dash;
                $nodes[]      = $root;
                $indentless[] = false;
                $depth        = 0;
            } else {
                while ($depth >= 0 && ($indent < $indents[$depth] || ($indentless[$depth] && $indent === $indents[$depth] && !$dash))) {
                    array_pop($indents);
                    array_pop($isSequence);
                    array_pop($nodes);
                    array_pop($indentless);
                    --$depth;
                }

                if ($depth < 0 || $indent !== $indents[$depth] || $dash !== $isSequence[$depth]) {
                    return null;
                }
            }

            $target = $nodes[$depth];
            $column = $indent;
            if ($dash) {
                $column = $indent + 1 + \strlen($m[4] ?? '');
                if (null === $key) {
                    $text              = (string)$valueOnly;
                    $target->content[] = new Node(NodeKindEnum::Scalar, ScalarResolver::resolve($text), NodeStyleEnum::Default, $text, line: $line, column: $column + 1);
                    ++$line;

                    continue;
                }

                $item              = new Node(NodeKindEnum::Mapping, CoreSchema::TAG_MAP, NodeStyleEnum::Default, line: $line, column: $column + 1);
                $target->content[] = $item;
                $indents[]         = $column;
                $isSequence[]      = false;
                $nodes[]           = $item;
                $indentless[]      = false;
                ++$depth;
                $target = $item;
            }

            $keyText           = (string)$key;
            $target->content[] = new Node(NodeKindEnum::Scalar, ScalarResolver::resolve($keyText), NodeStyleEnum::Default, $keyText, line: $line, column: $column + 1);
            $valueText         = $m[7] ?? null;
            if (null !== $valueText) {
                $target->content[] = new Node(
                    NodeKindEnum::Scalar,
                    ScalarResolver::resolve($valueText),
                    NodeStyleEnum::Default,
                    $valueText,
                    line: $line,
                    column: $column + \strlen($keyText) + 2 + \strlen($m[6] ?? ''),
                );
            } else {
                $pending       = $target;
                $pendingIndent = $column;
                $pendingLine   = $line;
                $pendingColumn = $column + \strlen($keyText) + 2;
            }

            ++$line;
        }

        if (null === $root) {
            return null;
        }

        if (null !== $pending) {
            $pending->content[] = new Node(NodeKindEnum::Scalar, CoreSchema::TAG_NULL, NodeStyleEnum::Default, line: $pendingLine, column: $pendingColumn);
        }

        return new Node(NodeKindEnum::Document, '', NodeStyleEnum::Default, '', [$root], line: $rootLine, column: 1);
    }
}
