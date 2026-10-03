<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\NodeStyle;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\FormatException;

/**
 * Reads HCL (native syntax) into the node model.
 *
 * - An attribute is a key; a block `type "a" "b" { ... }` nests as `type: {a: {b: {...}}}`; a repeated
 *   unlabelled block becomes a sequence of its bodies.
 * - String literals are double-quoted scalars; numbers, booleans and null keep their types; any other
 *   expression (arithmetic, references, calls, templates parts, `for` expressions) is kept as its source
 *   text in a plain string scalar flagged with `explicitEnd`.
 * - Markers invisible to YAML output let the HCL encoder rebuild the source shape: `explicitEnd` on a
 *   mapping means "its keys are block labels", `explicitStart` on a mapping means "object expression".
 * - Comments above an item become its key's head comment, a trailing comment its value's line comment.
 */
final class HclReader
{
    private int $pos = 0;

    private readonly int $length;

    private string $pending = '';

    public function __construct(private readonly string $source)
    {
        $this->length = \strlen($source);
        if (str_starts_with($source, "\u{FEFF}")) {
            $this->pos = 3;
        }
    }

    /**
     * @throws FormatException
     */
    public function read(): Node
    {
        $root                  = $this->body(false);
        $document              = Node::document($root);
        $document->footComment = $this->pending;

        return $document;
    }

    private function body(bool $nested): Node
    {
        $body = Node::mapping();
        while (true) {
            $this->skipBlank();
            if ($this->pos >= $this->length) {
                if ($nested) {
                    throw $this->error('unterminated block');
                }

                return $body;
            }

            if ('}' === $this->source[$this->pos]) {
                if (!$nested) {
                    throw $this->error('unexpected }');
                }

                ++$this->pos;
                $this->attachFoot($body);

                return $body;
            }

            $name = $this->identifier();
            $this->skipSpaces();
            $char = $this->source[$this->pos] ?? '';
            if ('=' === $char && '=' !== ($this->source[$this->pos + 1] ?? '')) {
                $this->attribute($body, $name);

                continue;
            }

            $this->block($body, $name);
        }
    }

    private function attribute(Node $body, string $name): void
    {
        ++$this->pos;
        $this->skipSpaces();
        $end        = HclScanner::expressionEnd($this->source, $this->pos);
        $text       = trim(substr($this->source, $this->pos, $end - $this->pos));
        $this->pos  = $end;
        $value      = $this->expression($text);
        $this->skipSpaces();
        $value->lineComment = $this->trailingComment();
        $this->set($body, $name, $value, $this->takePending());
    }

    private function block(Node $body, string $type): void
    {
        $labels = [];
        while (true) {
            $this->skipSpaces();
            $char = $this->source[$this->pos] ?? '';
            if ('{' === $char) {
                ++$this->pos;

                break;
            }

            if ('"' === $char) {
                $end       = HclScanner::skipString($this->source, $this->pos);
                $labels[]  = $this->unescape(substr($this->source, $this->pos + 1, $end - $this->pos - 2));
                $this->pos = $end;

                continue;
            }

            if ('' !== $char && 1 === preg_match('/[A-Za-z_]/', $char)) {
                $labels[] = $this->identifier();

                continue;
            }

            throw $this->error('expected a block label or { after ' . $type);
        }

        $head          = $this->takePending();
        $inner         = $this->body(true);
        $this->skipSpaces();
        $this->trailingComment();

        $this->place($body, $type, $labels, $inner, $head);
    }

    /**
     * @param list<string> $labels
     */
    private function place(Node $body, string $type, array $labels, Node $inner, string $head): void
    {
        if ([] === $labels) {
            $at = $this->find($body, $type);
            if (null === $at) {
                $this->set($body, $type, $inner, $head);

                return;
            }

            $existing = $body->content[$at + 1];
            if (NodeKind::Sequence === $existing->kind) {
                $existing->content[] = $inner;
            } else {
                NodeTools::replaceAt($body, $at + 1, Node::sequence([$existing, $inner]));
            }

            return;
        }

        $level = $this->labelLevel($body, $type, $head);
        $last  = \count($labels) - 1;
        foreach ($labels as $position => $label) {
            if ($position === $last) {
                $this->set($level, $label, $inner, '');

                return;
            }

            $level = $this->labelLevel($level, $label, '');
        }
    }

    private function labelLevel(Node $parent, string $key, string $head): Node
    {
        $at = $this->find($parent, $key);
        if (null !== $at && NodeKind::Mapping === $parent->content[$at + 1]->kind && $parent->content[$at + 1]->explicitEnd) {
            return $parent->content[$at + 1];
        }

        $level              = Node::mapping();
        $level->explicitEnd = true;
        $this->set($parent, $key, $level, $head);

        return $level;
    }

    private function set(Node $map, string $key, Node $value, string $head): void
    {
        $at = $this->find($map, $key);
        if (null !== $at) {
            NodeTools::replaceAt($map, $at + 1, $value);

            return;
        }

        $keyNode              = Node::scalar($key, CoreSchema::TAG_STR);
        $keyNode->headComment = $head;
        $map->content[]       = $keyNode;
        $map->content[]       = $value;
    }

    private function find(Node $map, string $key): ?int
    {
        $counter = \count($map->content);
        for ($i = 0; $i + 1 < $counter; $i += 2) {
            if ($map->content[$i]->value === $key) {
                return $i;
            }
        }

        return null;
    }

    private function attachFoot(Node $body): void
    {
        if ('' === $this->pending || [] === $body->content) {
            return;
        }

        $last              = $body->content[\count($body->content) - 2];
        $last->footComment = NodeTools::joinComments($last->footComment, $this->takePending());
    }

    private function expression(string $text): Node
    {
        if ('' === $text) {
            throw $this->error('expected an expression');
        }

        if (1 === preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $text)) {
            return Node::scalar($text, CoreSchema::TAG_INT);
        }

        if (1 === preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][-+]?[0-9]+)?$/D', $text)) {
            return Node::scalar($text, CoreSchema::TAG_FLOAT);
        }

        if ('true' === $text || 'false' === $text) {
            return Node::scalar($text, CoreSchema::TAG_BOOL);
        }

        if ('null' === $text) {
            return Node::scalar($text, CoreSchema::TAG_NULL);
        }

        $length = \strlen($text);
        if ('"' === $text[0] && HclScanner::skipString($text, 0) === $length) {
            return Node::scalar($this->unescape(substr($text, 1, -1)), CoreSchema::TAG_STR, NodeStyle::DoubleQuoted);
        }

        if (('[' === $text[0] || '{' === $text[0]) && $this->closesAtEnd($text) && !HclScanner::hasComment($text) && 1 !== preg_match('/^.\s*for\s/s', $text)) {
            $collection = '[' === $text[0] ? $this->list(substr($text, 1, -1)) : $this->object(substr($text, 1, -1));
            if ($collection instanceof Node) {
                return $collection;
            }
        }

        $raw              = Node::scalar($text, CoreSchema::TAG_STR);
        $raw->explicitEnd = true;

        return $raw;
    }

    private function list(string $inner): Node
    {
        $items = [];
        foreach (HclScanner::splitTop($inner, false) as $part) {
            $items[] = $this->expression($part);
        }

        return Node::sequence($items);
    }

    private function object(string $inner): ?Node
    {
        $content = [];
        foreach (HclScanner::splitTop($inner, true) as $entry) {
            if (1 !== preg_match('/^("(?:[^"\\\]|\\\.)*"|[A-Za-z_][A-Za-z0-9_-]*)\s*[=:]\s*(.+)$/s', $entry, $m)) {
                return null;
            }

            $key       = '"' === $m[1][0] ? $this->unescape(substr($m[1], 1, -1)) : $m[1];
            $content[] = Node::scalar($key, CoreSchema::TAG_STR);
            $content[] = $this->expression(trim($m[2]));
        }

        $object                = Node::mapping($content);
        $object->explicitStart = true;

        return $object;
    }

    private function closesAtEnd(string $text): bool
    {
        $length = \strlen($text);
        $depth  = 0;
        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            if ('"' === $char) {
                $i = HclScanner::skipString($text, $i) - 1;
            } elseif ('(' === $char || '[' === $char || '{' === $char) {
                ++$depth;
            } elseif (')' === $char || ']' === $char || '}' === $char) {
                --$depth;
                if (0 === $depth) {
                    return $i === $length - 1;
                }
            }
        }

        return false;
    }

    private function unescape(string $text): string
    {
        if (!str_contains($text, '\\')) {
            return $text;
        }

        return preg_replace_callback(
            '/\\\(u[0-9a-fA-F]{4}|U[0-9a-fA-F]{8}|[nrt"\\\])/',
            static function (array $m): string {
                $escape = $m[1];
                if ('u' === $escape[0] || 'U' === $escape[0]) {
                    $code = (int)hexdec(substr($escape, 1));

                    return $code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF) ? mb_chr($code, 'UTF-8') : "\u{FFFD}";
                }

                return match ($escape) {
                    'n'     => "\n",
                    'r'     => "\r",
                    't'     => "\t",
                    default => $escape,
                };
            },
            $text,
        ) ?? $text;
    }

    private function identifier(): string
    {
        if (1 !== preg_match('/\G[A-Za-z_][A-Za-z0-9_-]*/', $this->source, $m, 0, $this->pos)) {
            throw $this->error('expected an attribute or block name');
        }

        $this->pos += \strlen($m[0]);

        return $m[0];
    }

    /**
     * A comment after an item on the same line, as YAML comment text (empty when there is none).
     */
    private function trailingComment(): string
    {
        if ('#' === ($this->source[$this->pos] ?? '') || '//' === substr($this->source, $this->pos, 2)) {
            return $this->lineText();
        }

        return '';
    }

    private function lineText(): string
    {
        $start      = $this->pos + ('#' === $this->source[$this->pos] ? 1 : 2);
        $end        = $start     + strcspn($this->source, "\r\n", $start);
        $this->pos  = $end;
        $text       = trim(substr($this->source, $start, $end - $start));

        return '' === $text ? '#' : '# ' . $text;
    }

    /**
     * Skips whitespace and newlines, collecting comment lines as the pending head comment.
     */
    private function skipBlank(): void
    {
        while ($this->pos < $this->length) {
            $this->pos += strspn($this->source, " \t\r\n", $this->pos);
            $char       = $this->source[$this->pos] ?? '';
            if ('#' === $char || '//' === substr($this->source, $this->pos, 2)) {
                $this->pending = NodeTools::joinComments($this->pending, $this->lineText());

                continue;
            }

            if ('/*' === substr($this->source, $this->pos, 2)) {
                $end = strpos($this->source, '*/', $this->pos + 2);
                if (false === $end) {
                    throw $this->error('unterminated comment');
                }

                $text  = trim(substr($this->source, $this->pos + 2, $end - $this->pos - 2));
                $lines = [];
                foreach (explode("\n", $text) as $line) {
                    $lines[] = '# ' . trim($line, " \t\r*");
                }

                $this->pending = NodeTools::joinComments($this->pending, implode("\n", $lines));
                $this->pos     = $end + 2;

                continue;
            }

            return;
        }
    }

    private function skipSpaces(): void
    {
        $this->pos += strspn($this->source, " \t", $this->pos);
    }

    private function takePending(): string
    {
        $pending       = $this->pending;
        $this->pending = '';

        return $pending;
    }

    private function error(string $message): FormatException
    {
        return new FormatException(\sprintf('hcl: line %d: %s', substr_count($this->source, "\n", 0, min($this->pos, $this->length)) + 1, $message));
    }
}
