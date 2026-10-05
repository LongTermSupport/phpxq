<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\FormatException;

/**
 * A TOML 1.0 parser producing the node model: tables become block mappings (dotted keys and `[a.b]`
 * headers nest), arrays of tables become block sequences of block mappings, strings are `!!str`, numbers
 * `!!int`/`!!float`, dates `!!timestamp` with their source text. An inline table is a block mapping with
 * `explicitStart` set: the flag means nothing on a non-document node and the YAML emitter ignores it, so
 * YAML output stays block style as the reference prints it, while the TOML encoder writes it inline again.
 * Comments above an entry become its key's head comment; a trailing comment becomes the value's line
 * comment; comments at the end of the file become the document's foot comment.
 */
final class TomlParser
{
    private const string DATE_TIME = '/^\d{4}-\d{2}-\d{2}(?:[Tt ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:[Zz]|[+-]\d{2}:\d{2})?)?$|^\d{2}:\d{2}:\d{2}(?:\.\d+)?$/D';

    private const int MAX_DEPTH = 500;

    private const string KEY_PREFIX = 'key ';

    private int $pos = 0;

    private readonly int $length;

    private string $pending = '';

    /** @var array<int, array<string, int>> */
    private array $index = [];

    /** @var array<int, true> mappings created by dotted keys, which a `[table]` header may not reopen */
    private array $dotted = [];

    /** @var array<int, true> tables already opened by a `[table]` header, which may not be opened again */
    private array $headers = [];

    /** @var array<int, true> inline tables and plain arrays, which nothing may extend */
    private array $sealed = [];

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
    public function parse(): Node
    {
        $root    = Node::mapping();
        $current = $root;
        while (true) {
            $this->skipBlanks();
            if ($this->pos >= $this->length) {
                break;
            }

            $char = $this->source[$this->pos];
            if ("\n" === $char || "\r" === $char) {
                ++$this->pos;

                continue;
            }

            if ('#' === $char) {
                $this->pending = NodeTools::joinComments($this->pending, $this->readComment());

                continue;
            }

            if ('[' === $char) {
                $current = $this->header($root);

                continue;
            }

            $this->keyValue($current);
        }

        $document              = Node::document($root);
        $document->footComment = $this->pending;

        return $document;
    }

    private function header(Node $root): Node
    {
        $array = '[' === substr($this->source, $this->pos + 1, 1);
        $this->pos += $array ? 2 : 1;
        $path = $this->keyPath();
        $this->skipBlanks();
        $closing = $array ? ']]' : ']';
        if (substr($this->source, $this->pos, \strlen($closing)) !== $closing) {
            throw $this->error('expected ' . $closing . ' after table name');
        }

        $this->pos += \strlen($closing);
        $this->endOfLine();

        $comment       = $this->pending;
        $this->pending = '';
        $node          = $root;
        $last          = \count($path) - 1;
        foreach ($path as $position => $part) {
            if ($position < $last) {
                $node = $this->descend($node, $part);

                continue;
            }

            return $array ? $this->arrayItem($node, $part, $comment) : $this->table($node, $part, $comment);
        }

        throw $this->error('empty table name');
    }

    private function descend(Node $map, string $part): Node
    {
        $at = $this->find($map, $part);
        if (null === $at) {
            $child = Node::mapping();
            $this->put($map, Node::scalar($part, CoreSchema::TAG_STR), $child);

            return $child;
        }

        $child = $map->content[$at + 1];
        if (NodeKindEnum::Sequence === $child->kind && !isset($this->sealed[spl_object_id($child)]) && [] !== $child->content) {
            $child = $child->content[\count($child->content) - 1];
        }

        if (NodeKindEnum::Mapping !== $child->kind || isset($this->sealed[spl_object_id($child)])) {
            throw $this->error(self::KEY_PREFIX . $part . ' is not a table');
        }

        return $child;
    }

    private function table(Node $parent, string $part, string $comment): Node
    {
        $at = $this->find($parent, $part);
        if (null === $at) {
            $child            = Node::mapping();
            $key              = Node::scalar($part, CoreSchema::TAG_STR);
            $key->headComment = $comment;
            $this->put($parent, $key, $child);
            $this->headers[spl_object_id($child)] = true;

            return $child;
        }

        $child = $parent->content[$at + 1];
        if (NodeKindEnum::Mapping !== $child->kind || isset($this->sealed[spl_object_id($child)]) || isset($this->dotted[spl_object_id($child)]) || isset($this->headers[spl_object_id($child)])) {
            throw $this->error('table ' . $part . ' is already defined');
        }

        if ('' !== $comment) {
            $parent->content[$at]->headComment = $comment;
        }

        $this->headers[spl_object_id($child)] = true;

        return $child;
    }

    private function arrayItem(Node $parent, string $part, string $comment): Node
    {
        $item              = Node::mapping();
        $item->headComment = $comment;

        $at                = $this->find($parent, $part);
        if (null === $at) {
            $this->put($parent, Node::scalar($part, CoreSchema::TAG_STR), Node::sequence([$item]));

            return $item;
        }

        $existing = $parent->content[$at + 1];
        if (NodeKindEnum::Sequence !== $existing->kind || isset($this->sealed[spl_object_id($existing)])) {
            throw $this->error(self::KEY_PREFIX . $part . ' is not an array of tables');
        }

        $existing->content[] = $item;

        return $item;
    }

    private function keyValue(Node $table): void
    {
        $path = $this->keyPath();
        $this->skipBlanks();
        if ('=' !== substr($this->source, $this->pos, 1)) {
            throw $this->error('expected = after key');
        }

        ++$this->pos;
        $this->skipBlanks();
        $value = $this->value(0);
        $this->skipBlanks();
        if ('#' === substr($this->source, $this->pos, 1)) {
            $value->lineComment = $this->readComment();
        }

        $this->endOfLine();

        $comment       = $this->pending;
        $this->pending = '';
        $this->insert($table, $value, $comment, ...$path);
    }

    private function insert(Node $table, Node $value, string $comment, string ...$path): void
    {
        $last = \count($path) - 1;
        $node = $table;
        foreach ($path as $position => $part) {
            $at = $this->find($node, $part);
            if ($position === $last) {
                if (null !== $at) {
                    throw $this->error(self::KEY_PREFIX . $part . ' is already defined');
                }

                $key              = Node::scalar($part, CoreSchema::TAG_STR);
                $key->headComment = $comment;
                $this->put($node, $key, $value);

                return;
            }

            if (null === $at) {
                $child                               = Node::mapping();
                $this->dotted[spl_object_id($child)] = true;
                $this->put($node, Node::scalar($part, CoreSchema::TAG_STR), $child);
                $node = $child;

                continue;
            }

            $child = $node->content[$at + 1];
            if (NodeKindEnum::Mapping !== $child->kind || isset($this->sealed[spl_object_id($child)])) {
                throw $this->error(self::KEY_PREFIX . $part . ' is not a table');
            }

            $node = $child;
        }
    }

    /**
     * @return list<string>
     */
    private function keyPath(): array
    {
        $path = [];
        while (true) {
            $this->skipBlanks();
            $path[] = $this->keyPart();
            $this->skipBlanks();
            if ('.' !== substr($this->source, $this->pos, 1)) {
                return $path;
            }

            ++$this->pos;
        }
    }

    private function keyPart(): string
    {
        $char = substr($this->source, $this->pos, 1);
        if ('"' === $char) {
            return $this->basicString();
        }

        if ("'" === $char) {
            return $this->literalString();
        }

        $n = strspn($this->source, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-', $this->pos);
        if (0 === $n) {
            throw $this->error('expected a key');
        }

        $key        = substr($this->source, $this->pos, $n);
        $this->pos += $n;

        return $key;
    }

    private function value(int $depth): Node
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->error('nesting is too deep');
        }

        $char = substr($this->source, $this->pos, 1);

        return match ($char) {
            '"'     => $this->stringValue(true),
            "'"     => $this->stringValue(false),
            '['     => $this->arrayValue($depth),
            '{'     => $this->inlineTable($depth),
            ''      => throw $this->error('expected a value'),
            default => $this->bareValue(),
        };
    }

    private function stringValue(bool $basic): Node
    {
        $text = $basic ? $this->basicString() : $this->literalString();

        return Node::scalar($text, CoreSchema::TAG_STR);
    }

    private function arrayValue(int $depth): Node
    {
        ++$this->pos;
        $items = [];
        while (true) {
            $this->skipSpace();
            if ($this->pos >= $this->length) {
                throw $this->error('unterminated array');
            }

            if (']' === $this->source[$this->pos]) {
                ++$this->pos;

                $array                               = Node::sequence($items);
                $this->sealed[spl_object_id($array)] = true;

                return $array;
            }

            $items[] = $this->value($depth + 1);
            $this->skipSpace();
            $char = substr($this->source, $this->pos, 1);
            if (',' === $char) {
                ++$this->pos;

                continue;
            }

            if (']' !== $char) {
                throw $this->error('expected , or ] in array');
            }
        }
    }

    private function inlineTable(int $depth): Node
    {
        ++$this->pos;
        $table                               = Node::mapping();
        $table->explicitStart                = true;
        $this->sealed[spl_object_id($table)] = true;
        $this->skipBlanks();
        if ('}' === substr($this->source, $this->pos, 1)) {
            ++$this->pos;

            return $table;
        }

        while (true) {
            $this->skipSpace();
            $path = $this->keyPath();
            $this->skipBlanks();
            if ('=' !== substr($this->source, $this->pos, 1)) {
                throw $this->error('expected = after key in inline table');
            }

            ++$this->pos;
            $this->skipBlanks();
            $this->insert($table, $this->value($depth + 1), '', ...$path);
            $this->skipSpace();
            $char = substr($this->source, $this->pos, 1);
            ++$this->pos;
            if ('}' === $char) {
                return $table;
            }

            if (',' !== $char) {
                throw $this->error('expected , or } in inline table');
            }
        }
    }

    private function bareValue(): Node
    {
        $n     = strcspn($this->source, " \t\r\n,]}#", $this->pos);
        $token = substr($this->source, $this->pos, $n);
        if ('' === $token) {
            throw $this->error('expected a value');
        }

        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/D', $token) && 1 === preg_match('/\G [0-9]{2}:[0-9]{2}/', $this->source, $m, 0, $this->pos + $n)) {
            $extra = strcspn($this->source, " \t\r\n,]}#", $this->pos + $n + 1);
            $token .= ' ' . substr($this->source, $this->pos + $n + 1, $extra);
            $n     += 1 + $extra;
        }

        $this->pos += $n;

        if ('true' === $token || 'false' === $token) {
            return Node::scalar($token, CoreSchema::TAG_BOOL);
        }

        if (1 === preg_match('/^([+-]?)(inf|nan)$/D', $token, $m)) {
            return Node::scalar(('-' === $m[1] ? '-' : '') . '.' . $m[2], CoreSchema::TAG_FLOAT);
        }

        if (1 === preg_match(self::DATE_TIME, $token)) {
            return Node::scalar($token, '!!timestamp');
        }

        $clean = str_replace('_', '', $token);
        if (1 === preg_match('/^[+-]?(?:0|[1-9](?:_?[0-9])*)$/D', $token)) {
            return Node::scalar(ltrim($clean, '+'), CoreSchema::TAG_INT);
        }

        if (1 === preg_match('/^0x[0-9A-Fa-f](?:_?[0-9A-Fa-f])*$/D', $token) || 1 === preg_match('/^0o[0-7](?:_?[0-7])*$/D', $token)) {
            return Node::scalar($clean, CoreSchema::TAG_INT);
        }

        if (1 === preg_match('/^0b[01](?:_?[01])*$/D', $token) && 1 === preg_match('/^0b([01]+)$/D', $clean, $m)) {
            return Node::scalar((string)NodeTools::integerText('0o' . $this->binaryToOctal($m[1])), CoreSchema::TAG_INT);
        }

        if (1 === preg_match('/^[+-]?(?:0|[1-9](?:_?[0-9])*)(?:\.[0-9](?:_?[0-9])*)?(?:[eE][+-]?[0-9](?:_?[0-9])*)?$/D', $token) && 1 === preg_match('/[.eE]/', $token)) {
            return Node::scalar(ltrim($clean, '+'), CoreSchema::TAG_FLOAT);
        }

        throw $this->error('invalid value ' . $token);
    }

    private function binaryToOctal(string $bits): string
    {
        $bits = str_pad($bits, (int)(3 * ceil(\strlen($bits) / 3)), '0', \STR_PAD_LEFT);
        $out  = '';
        foreach (str_split($bits, 3) as $group) {
            $out .= (string)bindec($group);
        }

        return $out;
    }

    private function literalString(): string
    {
        if ("'''" === substr($this->source, $this->pos, 3)) {
            $start = $this->pos + 3;
            if ("\r\n" === substr($this->source, $start, 2)) {
                $start += 2;
            } elseif ("\n" === substr($this->source, $start, 1)) {
                ++$start;
            }

            $end = strpos($this->source, "'''", $start);
            if (false === $end) {
                throw $this->error('unterminated multi-line literal string');
            }

            while ("'" === substr($this->source, $end + 3, 1)) {
                ++$end;
            }

            $this->pos = $end + 3;

            return substr($this->source, $start, $end - $start);
        }

        $end = strpos($this->source, "'", $this->pos + 1);
        if (false === $end) {
            throw $this->error('unterminated literal string');
        }

        $text = substr($this->source, $this->pos + 1, $end - $this->pos - 1);
        if (str_contains($text, "\n")) {
            throw $this->error('newline in literal string');
        }

        $this->pos = $end + 1;

        return $text;
    }

    private function basicString(): string
    {
        $multiline = '"""' === substr($this->source, $this->pos, 3);
        $this->pos += $multiline ? 3 : 1;
        if ($multiline) {
            if ("\r\n" === substr($this->source, $this->pos, 2)) {
                $this->pos += 2;
            } elseif ("\n" === substr($this->source, $this->pos, 1)) {
                ++$this->pos;
            }
        }

        $out = '';
        while (true) {
            $run = strcspn($this->source, "\"\\\n\r", $this->pos);
            $out .= substr($this->source, $this->pos, $run);
            $this->pos += $run;
            if ($this->pos >= $this->length) {
                throw $this->error('unterminated string');
            }

            $char = $this->source[$this->pos];
            if ('"' === $char) {
                if (!$multiline) {
                    ++$this->pos;

                    return $out;
                }

                if ('"""' === substr($this->source, $this->pos, 3)) {
                    $this->pos += 3;
                    while ('"' === substr($this->source, $this->pos, 1) && !str_ends_with($out, '""')) {
                        $out .= '"';
                        ++$this->pos;
                    }

                    return $out;
                }

                $out .= '"';
                ++$this->pos;

                continue;
            }

            if ("\n" === $char || "\r" === $char) {
                if (!$multiline) {
                    throw $this->error('newline in string');
                }

                $out .= $char;
                ++$this->pos;

                continue;
            }

            $out .= $this->escape($multiline);
        }
    }

    private function escape(bool $multiline): string
    {
        $next = substr($this->source, $this->pos + 1, 1);
        $this->pos += 2;
        switch ($next) {
            case 'b':
                return "\x08";
            case 't':
                return "\t";
            case 'n':
                return "\n";
            case 'f':
                return "\x0c";
            case 'r':
                return "\r";
            case '"':
                return '"';
            case '\\':
                return '\\';
            case 'u':
                return $this->unicode(4);
            case 'U':
                return $this->unicode(8);
            default:
                if ($multiline && (' ' === $next || "\t" === $next || "\n" === $next || "\r" === $next)) {
                    --$this->pos;
                    $this->pos += strspn($this->source, " \t\r\n", $this->pos);

                    return '';
                }

                throw $this->error('invalid escape sequence \\' . $next);
        }
    }

    private function unicode(int $digits): string
    {
        $hex = substr($this->source, $this->pos, $digits);
        if (\strlen($hex) !== $digits || \strlen($hex) !== strspn($hex, '0123456789abcdefABCDEF')) {
            throw $this->error('invalid unicode escape');
        }

        $this->pos += $digits;
        $code       = (int)hexdec($hex);
        if ($code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
            throw $this->error('invalid unicode scalar value');
        }

        return mb_chr($code, 'UTF-8');
    }

    private function readComment(): string
    {
        $end       = $this->pos + strcspn($this->source, "\r\n", $this->pos);
        $text      = rtrim(substr($this->source, $this->pos, $end - $this->pos));
        $this->pos = $end;

        return $text;
    }

    private function endOfLine(): void
    {
        $this->skipBlanks();
        if ('#' === substr($this->source, $this->pos, 1)) {
            $this->pos += strcspn($this->source, "\r\n", $this->pos);
        }

        $char = substr($this->source, $this->pos, 1);
        if ('' === $char) {
            return;
        }

        if ("\n" !== $char && "\r" !== $char) {
            throw $this->error('unexpected characters after value');
        }
    }

    private function skipBlanks(): void
    {
        $this->pos += strspn($this->source, " \t", $this->pos);
    }

    /**
     * Whitespace, newlines and comments (inside arrays and inline tables).
     */
    private function skipSpace(): void
    {
        while ($this->pos < $this->length) {
            $this->pos += strspn($this->source, " \t\r\n", $this->pos);
            if ('#' !== substr($this->source, $this->pos, 1)) {
                return;
            }

            $this->pos += strcspn($this->source, "\r\n", $this->pos);
        }
    }

    private function find(Node $map, string $key): ?int
    {
        $id = spl_object_id($map);
        if (!isset($this->index[$id])) {
            $this->index[$id] = [];
            $counter          = \count($map->content);
            for ($i = 0; $i + 1 < $counter; $i += 2) {
                $this->index[$id][$map->content[$i]->value] = $i;
            }
        }

        return $this->index[$id][$key] ?? null;
    }

    private function put(Node $map, Node $key, Node $value): void
    {
        $id = spl_object_id($map);
        if (!isset($this->index[$id])) {
            $this->find($map, '');
        }

        $this->index[$id][$key->value] = \count($map->content);
        $map->content[]                = $key;
        $map->content[]                = $value;
    }

    private function error(string $message): FormatException
    {
        return new FormatException(\sprintf('toml: line %d: %s', substr_count($this->source, "\n", 0, min($this->pos, $this->length)) + 1, $message));
    }
}
