<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Emitter;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;

/**
 * Writes one node tree as YAML text. A port of the go-yaml v3 / libyaml emitter state (column, indent,
 * whitespace and indention flags) driven directly by the Node tree, so indentation, quoting and comment
 * placement match the reference byte for byte. Lines are never wrapped (yq disables go-yaml's folding).
 *
 * @internal
 */
final class YamlWriter
{
    private const int COLOR_KEY     = 36;

    private const int COLOR_STRING  = 32;

    private const int COLOR_NUMBER  = 95;

    private const int COLOR_ANCHOR  = 93;

    private const int COLOR_COMMENT = 90;

    private const string TAG_PREFIX = 'tag:yaml.org,2002:';

    private const string TIMESTAMP = '/\A[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}(?:(?:[Tt]|[ \t]+)[0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2}(?:\.[0-9]*)?(?:[ \t]*(?:Z|[-+][0-9]{1,2}(?::[0-9]{2})?))?)?\z/';

    private const string PLAIN_WORD = '/\A[A-Za-z_][A-Za-z0-9_ -]*+(?<! )\z/';

    private const string DOUBLE_QUOTE_SPECIALS = '/["\\\\\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\xA8\xA9]|\xEF\xBB\xBF|\xEF\xBF[\xBE\xBF]|[\xF0-\xF7][\x80-\xBF]{3}/';

    /**
     * Plain words the core schema resolves to something other than a string.
     */
    private const array RESERVED_WORDS = [
        'null'  => true, 'Null' => true, 'NULL' => true,
        'true'  => true, 'True' => true, 'TRUE' => true,
        'false' => true, 'False' => true, 'FALSE' => true,
    ];

    /**
     * YAML 1.1 booleans: `-P` keeps them quoted so a YAML 1.1 reader still sees strings.
     */
    private const array OLD_BOOLS = [
        'y'   => true, 'Y' => true, 'yes' => true, 'Yes' => true, 'YES' => true,
        'n'   => true, 'N' => true, 'no' => true, 'No' => true, 'NO' => true,
        'on'  => true, 'On' => true, 'ON' => true,
        'off' => true, 'Off' => true, 'OFF' => true,
    ];

    private const array ESCAPES = [
        "\0"     => '0', "\x07" => 'a', "\x08" => 'b', "\t" => 't', "\n" => 'n', "\x0B" => 'v',
        "\x0C"   => 'f', "\r" => 'r', "\x1B" => 'e', '"' => '"', '\\' => '\\',
        "\u{85}" => 'N', "\u{A0}" => '_', "\u{2028}" => 'L', "\u{2029}" => 'P',
    ];

    private string $out = '';

    private int $column = 0;

    private bool $whitespace = true;

    private bool $indention = true;

    private int $indent = -1;

    private int $flowLevel = 0;

    public function __construct(private readonly EmitOptions $options)
    {
    }

    /**
     * Renders a node (the root of a document, or any evaluator result) followed by an optional extra
     * foot comment, ending with exactly one newline unless nothing was written.
     */
    public function render(Node $root, string $footComment = ''): string
    {
        $block = $this->isBlockCollection($root);
        if (!$block && '' !== $root->headComment) {
            $this->writeIndent();
            $this->writeComment($root->headComment);
        }

        $this->emitNode($root, false, null, null, $block ? $root->lineComment : '');
        if (!$block && '' !== $root->lineComment) {
            $this->writeLineComment($root->lineComment);
        }

        if (!$this->indention) {
            if ($this->column > 0) {
                $this->putBreak();
            }

            $this->indention  = true;
            $this->whitespace = true;
        }

        foreach ([$root->footComment, $footComment] as $foot) {
            if ('' !== $foot) {
                $this->writeIndent();
                $this->writeComment($foot);
            }
        }

        return $this->out;
    }

    /**
     * A comment block as standalone lines, one trailing newline.
     */
    public function commentBlock(string $comment): string
    {
        $this->writeIndent();
        $this->writeComment($comment);

        return $this->out;
    }

    // ----------------------------------------------------------------------------------------------
    // nodes

    /**
     * @param array{tag: string, style: NodeStyleEnum, value: string, analysis: ScalarAnalysis}|null $plan
     */
    private function emitNode(Node $node, bool $simpleKey, ?array $plan, ?int $color, string $headerComment): void
    {
        switch ($node->kind) {
            case NodeKindEnum::Alias:
                $this->writeAnchor('*', $node->value);
                if ($simpleKey) {
                    $this->put(' ');
                }

                return;

            case NodeKindEnum::Scalar:
                $this->emitScalar($node, $plan ?? $this->planScalar($node), $simpleKey, $color);

                return;

            case NodeKindEnum::Document:
                $this->emitNode($node->root(), $simpleKey, null, $color, $headerComment);

                return;

            default:
                $this->emitCollection($node, $headerComment);
        }
    }

    private function isBlockCollection(Node $node): bool
    {
        return (NodeKindEnum::Mapping === $node->kind || NodeKindEnum::Sequence === $node->kind)
            && [] !== $node->content
            && 0 === $this->flowLevel
            && NodeStyleEnum::Flow !== $this->effectiveStyle($node);
    }

    private function effectiveStyle(Node $node): NodeStyleEnum
    {
        return $this->options->prettyPrint ? NodeStyleEnum::Default : $node->style;
    }

    private function emitCollection(Node $node, string $headerComment): void
    {
        $this->emitAnchorAndCollectionTag($node);

        if (!$this->isBlockCollection($node)) {
            if (NodeKindEnum::Mapping === $node->kind) {
                $this->emitFlowMapping($node);
            } else {
                $this->emitFlowSequence($node);
            }

            return;
        }

        if ('' !== $headerComment) {
            $this->writeLineComment($headerComment);
        }

        if (NodeKindEnum::Mapping === $node->kind) {
            $this->emitBlockMapping($node);
        } else {
            $this->emitBlockSequence($node);
        }
    }

    private function emitAnchorAndCollectionTag(Node $node): void
    {
        if ('' !== $node->anchor) {
            $this->writeAnchor('&', $node->anchor);
        }

        $tag = $node->tag;
        if ('' === $tag) {
            return;
        }

        if (!$node->tagExplicit) {
            $implicit = NodeKindEnum::Mapping === $node->kind ? '!!map' : '!!seq';
            if ($this->shortTag($tag) === $implicit) {
                return;
            }
        }

        $this->writeTag($this->tagText($tag));
    }

    private function emitBlockMapping(Node $mapping): void
    {
        $saved = $this->indent;
        $this->increaseIndent();

        $head  = $mapping->headComment;
        $count = \count($mapping->content);
        for ($i = 0; $i < $count; $i += 2) {
            $key   = $mapping->content[$i];
            $value = $mapping->content[$i + 1] ?? new Node(NodeKindEnum::Scalar, '!!null');

            $valueBlock = $this->isBlockCollection($value);
            $heads      = [$head, $key->headComment];
            if (!$valueBlock) {
                $heads[] = $value->headComment;
            }

            $head = '';
            $this->writeHeadComments($heads);

            $this->writeIndent();
            $plan = NodeKindEnum::Scalar === $key->kind ? $this->planScalar($key) : null;
            if ($this->checkSimpleKey($key, $plan)) {
                $this->emitNode($key, true, $plan, self::COLOR_KEY, '');
                $this->indicator(':', false, false, false);
            } else {
                $this->indicator('?', true, false, true);
                $this->emitNode($key, false, $plan, self::COLOR_KEY, '');
                $this->writeIndent();
                $this->indicator(':', true, false, true);
            }

            $lineComment = '' !== $value->lineComment ? $value->lineComment : $key->lineComment;
            $this->emitNode($value, false, null, null, $valueBlock ? $lineComment : '');
            if (!$valueBlock && '' !== $lineComment) {
                $this->writeLineComment($lineComment);
            }

            $this->writeFootComments([$key->footComment, $value->footComment]);
        }

        $this->indent = $saved;
    }

    private function emitBlockSequence(Node $sequence): void
    {
        $saved = $this->indent;
        $this->increaseIndent();

        $head = $sequence->headComment;
        foreach ($sequence->content as $item) {
            $this->writeHeadComments([$head, $item->headComment]);
            $head = '';

            $this->writeIndent();
            $this->indicator('-', true, false, true);

            $itemBlock = $this->isBlockCollection($item);
            $this->emitNode($item, false, null, null, $itemBlock ? $item->lineComment : '');
            if (!$itemBlock && '' !== $item->lineComment) {
                $this->writeLineComment($item->lineComment);
            }

            $this->writeFootComments([$item->footComment]);
        }

        $this->indent = $saved;
    }

    private function emitFlowSequence(Node $sequence): void
    {
        $this->indicator('[', true, true, false);
        ++$this->flowLevel;
        foreach ($sequence->content as $i => $item) {
            if ($i > 0) {
                $this->indicator(',', false, false, false);
            }

            $this->emitNode($item, false, null, null, '');
        }

        --$this->flowLevel;
        $this->indicator(']', false, false, false);
    }

    private function emitFlowMapping(Node $mapping): void
    {
        $this->indicator('{', true, true, false);
        ++$this->flowLevel;
        $count = \count($mapping->content);
        for ($i = 0; $i < $count; $i += 2) {
            $key   = $mapping->content[$i];
            $value = $mapping->content[$i + 1] ?? new Node(NodeKindEnum::Scalar, '!!null');
            if ($i > 0) {
                $this->indicator(',', false, false, false);
            }

            $plan = NodeKindEnum::Scalar === $key->kind ? $this->planScalar($key) : null;
            if ($this->checkSimpleKey($key, $plan)) {
                $this->emitNode($key, true, $plan, self::COLOR_KEY, '');
                $this->indicator(':', false, false, false);
            } else {
                $this->indicator('?', true, false, false);
                $this->emitNode($key, false, $plan, self::COLOR_KEY, '');
                $this->indicator(':', true, false, false);
            }

            $this->emitNode($value, false, null, null, '');
        }

        --$this->flowLevel;
        $this->indicator('}', false, false, false);
    }

    /**
     * @param array{tag: string, style: NodeStyleEnum, value: string, analysis: ScalarAnalysis}|null $plan
     */
    private function checkSimpleKey(Node $key, ?array $plan): bool
    {
        switch ($key->kind) {
            case NodeKindEnum::Alias:
                $length = \strlen($key->value);

                break;

            case NodeKindEnum::Scalar:
                $plan ??= $this->planScalar($key);
                if ($plan['analysis']->multiline) {
                    return false;
                }

                $length = \strlen($key->anchor) + \strlen($plan['tag']) + \strlen($plan['value']);

                break;

            case NodeKindEnum::Sequence:
            case NodeKindEnum::Mapping:
                if ([] !== $key->content) {
                    return false;
                }

                $length = \strlen($key->anchor) + \strlen($key->tag);

                break;

            default:
                return false;
        }

        return $length <= 128;
    }

    // ----------------------------------------------------------------------------------------------
    // scalars

    /**
     * Decides the tag to print (empty when implicit), the requested style and the text to write.
     *
     * @return array{tag: string, style: NodeStyleEnum, value: string, analysis: ScalarAnalysis}
     */
    private function planScalar(Node $node): array
    {
        $value = $node->value;
        $style = $node->style;
        $stag  = $this->shortTag($node->tag);
        $tag   = $stag;

        if ($this->options->prettyPrint && (NodeStyleEnum::DoubleQuoted === $style || NodeStyleEnum::SingleQuoted === $style)) {
            $style = isset(self::OLD_BOOLS[$value]) ? NodeStyleEnum::DoubleQuoted : NodeStyleEnum::Default;
        }

        if ('' !== $value && !mb_check_encoding($value, 'UTF-8')) {
            $encoded = base64_encode($value);
            $value   = implode("\n", str_split($encoded, 70));
            $stag    = '!!binary';
            $tag     = $stag;
            $style   = NodeStyleEnum::Default;
        }

        $quoted = NodeStyleEnum::DoubleQuoted                                                                                                                                === $style || NodeStyleEnum::SingleQuoted === $style
                                                                                                                                           || NodeStyleEnum::Literal         === $style || NodeStyleEnum::Folded === $style;

        $force = false;
        if ('' !== $tag && !$node->tagExplicit) {
            if ('!!str' === $stag && $quoted) {
                $tag = '';
            } elseif ('!!str' === $stag && 1 === preg_match(self::PLAIN_WORD, $value) && !isset(self::RESERVED_WORDS[$value])) {
                $tag = '';
            } else {
                $resolved = $this->resolveImplicit($value);
                if ($resolved === $stag) {
                    $tag = '';
                } elseif ('!!str' === $stag) {
                    $tag   = '';
                    $force = '<<' !== $value;
                }
            }
        }

        if ($quoted) {
            $final = $style;
        } elseif (str_contains($value, "\n")) {
            $final = $this->flowLevel > 0 ? NodeStyleEnum::DoubleQuoted : NodeStyleEnum::Literal;
        } elseif ($force) {
            $final = NodeStyleEnum::DoubleQuoted;
        } else {
            $final = NodeStyleEnum::Default;
        }

        return [
            'tag'      => '' === $tag ? '' : $this->tagText($tag),
            'style'    => $final,
            'value'    => $value,
            'analysis' => ScalarAnalysis::of($value),
        ];
    }

    /**
     * The tag the core schema (go-yaml flavour) gives an unquoted scalar.
     */
    private function resolveImplicit(string $value): string
    {
        if ('<<' === $value) {
            return '!!merge';
        }

        $tag = CoreSchema::resolve($value);
        if (CoreSchema::TAG_STR !== $tag || '' === $value) {
            return $tag;
        }

        $first = $value[0];
        if ($first >= '0' && $first <= '9') {
            if (1 === preg_match(self::TIMESTAMP, $value)) {
                return CoreSchema::TAG_TIMESTAMP;
            }

            if (1 === preg_match('/\A0b[01]+\z/', $value)) {
                return CoreSchema::TAG_INT;
            }
        }

        if (('+' === $first || '-' === $first || '.' === $first || ($first >= '0' && $first <= '9')) && str_contains($value, '_')) {
            $stripped = CoreSchema::resolve(str_replace('_', '', $value));
            if (CoreSchema::TAG_INT === $stripped || CoreSchema::TAG_FLOAT === $stripped) {
                return $stripped;
            }
        }

        return $tag;
    }

    /**
     * @param array{tag: string, style: NodeStyleEnum, value: string, analysis: ScalarAnalysis} $plan
     */
    private function emitScalar(Node $node, array $plan, bool $simpleKey, ?int $color): void
    {
        $analysis = $plan['analysis'];
        $value    = $plan['value'];
        $style    = $plan['style'];

        if (NodeStyleEnum::Default === $style) {
            if (($this->flowLevel > 0 && !$analysis->flowPlainAllowed) || (0 === $this->flowLevel && !$analysis->blockPlainAllowed)) {
                $style = NodeStyleEnum::SingleQuoted;
            }

            if ('' === $value && ($this->flowLevel > 0 || $simpleKey)) {
                $style = NodeStyleEnum::SingleQuoted;
            }
        }

        if (NodeStyleEnum::SingleQuoted === $style && !$analysis->singleQuotedAllowed) {
            $style = NodeStyleEnum::DoubleQuoted;
        }

        if ((NodeStyleEnum::Literal === $style || NodeStyleEnum::Folded === $style) && (!$analysis->blockAllowed || $this->flowLevel > 0 || $simpleKey)) {
            $style = NodeStyleEnum::DoubleQuoted;
        }

        if ('' !== $node->anchor) {
            $this->writeAnchor('&', $node->anchor);
        }

        if ('' !== $plan['tag']) {
            $this->writeTag($plan['tag']);
        }

        if (null === $color && $this->options->colors) {
            $color = $this->valueColor('' === $node->tag ? $this->resolveImplicit($value) : $this->shortTag($node->tag));
        }

        $saved = $this->indent;
        $this->increaseIndent();
        switch ($style) {
            case NodeStyleEnum::SingleQuoted:
                $this->writeSingleQuoted($value, $color);

                break;

            case NodeStyleEnum::DoubleQuoted:
                $this->writeDoubleQuoted($value, $color);

                break;

            case NodeStyleEnum::Literal:
            case NodeStyleEnum::Folded:
                if ($saved < 0) {
                    $this->indent = $this->options->indent;
                }

                if (NodeStyleEnum::Literal === $style) {
                    $this->writeLiteral($value, $color);
                } else {
                    $this->writeFolded($value, $color);
                }

                break;

            default:
                $this->writePlain($value, $color);
        }

        $this->indent = $saved;
    }

    private function valueColor(string $tag): ?int
    {
        return match ($tag) {
            '!!bool', '!!int', '!!float' => self::COLOR_NUMBER,
            '!!null'                     => null,
            default                      => self::COLOR_STRING,
        };
    }

    private function writePlain(string $value, ?int $color): void
    {
        if (!$this->whitespace && ('' !== $value || $this->flowLevel > 0)) {
            $this->put(' ');
        }

        if ('' !== $value) {
            $this->put($value, $color);
        }

        $this->whitespace = false;
        $this->indention  = false;
    }

    private function writeSingleQuoted(string $value, ?int $color): void
    {
        $this->indicator("'", true, false, false, $color);

        $breaks = false;
        foreach (explode("\n", $value) as $j => $part) {
            if ($j > 0) {
                if (!$breaks) {
                    $this->putBreak();
                }

                $this->putBreak();
                $this->indention = true;
                $breaks          = true;
            }

            if ('' !== $part) {
                if ($breaks) {
                    $this->writeIndent();
                }

                $this->put(str_replace("'", "''", $part), $color);
                $this->indention = false;
                $breaks          = false;
            }
        }

        if ($breaks) {
            $this->writeIndent();
        }

        $this->indicator("'", false, false, false, $color);
        $this->whitespace = false;
        $this->indention  = false;
    }

    private function writeDoubleQuoted(string $value, ?int $color): void
    {
        $this->indicator('"', true, false, false, $color);

        $escaped = preg_replace_callback(
            self::DOUBLE_QUOTE_SPECIALS,
            static function (array $match): string {
                $char = $match[0];
                if (isset(self::ESCAPES[$char])) {
                    return '\\' . self::ESCAPES[$char];
                }

                $code = 1 === \strlen($char) ? \ord($char[0]) : mb_ord($char, 'UTF-8');
                if ($code <= 0xFF) {
                    return \sprintf('\x%02X', $code);
                }

                if ($code <= 0xFFFF) {
                    return \sprintf('\u%04X', $code);
                }

                return \sprintf('\U%08X', $code);
            },
            $value,
        );

        if ('' !== ($escaped ?? $value)) {
            $this->put($escaped ?? $value, $color);
        }

        $this->indicator('"', false, false, false, $color);
        $this->whitespace = false;
        $this->indention  = false;
    }

    private function writeBlockScalarHints(string $value): void
    {
        if ('' !== $value && (' ' === $value[0] || "\n" === $value[0])) {
            $this->indicator((string)$this->options->indent, false, false, false);
        }

        $length = \strlen($value);
        if (0 === $length || "\n" !== $value[$length - 1]) {
            $chomp = '-';
        } elseif (1 === $length || "\n" === $value[$length - 2]) {
            $chomp = '+';
        } else {
            $chomp = '';
        }

        if ('' !== $chomp) {
            $this->indicator($chomp, false, false, false);
        }
    }

    private function writeLiteral(string $value, ?int $color): void
    {
        $this->indicator('|', true, false, false);
        $this->writeBlockScalarHints($value);
        $this->putBreak();
        $this->indention  = true;
        $this->whitespace = true;

        $breaks = true;
        foreach (explode("\n", $value) as $j => $part) {
            if ($j > 0) {
                $this->putBreak();
                $this->indention = true;
                $breaks          = true;
            }

            if ('' !== $part) {
                if ($breaks) {
                    $this->writeIndent();
                }

                $this->put($part, $color);
                $this->indention = false;
                $breaks          = false;
            }
        }
    }

    private function writeFolded(string $value, ?int $color): void
    {
        $this->indicator('>', true, false, false);
        $this->writeBlockScalarHints($value);
        $this->putBreak();
        $this->indention  = true;
        $this->whitespace = true;

        $parts          = explode("\n", $value);
        $breaks         = true;
        $leadingSpaces  = true;
        $partCount      = \count($parts);
        foreach ($parts as $j => $part) {
            if ($j > 0) {
                if (!$breaks && !$leadingSpaces) {
                    $k = $j;
                    while ($k < $partCount && '' === $parts[$k]) {
                        ++$k;
                    }

                    if ($k < $partCount && ' ' !== $parts[$k][0] && "\t" !== $parts[$k][0]) {
                        $this->putBreak();
                    }
                }

                $this->putBreak();
                $this->indention = true;
                $breaks          = true;
            }

            if ('' !== $part) {
                if ($breaks) {
                    $this->writeIndent();
                    $leadingSpaces = ' ' === $part[0] || "\t" === $part[0];
                }

                $this->put($part, $color);
                $this->indention = false;
                $breaks          = false;
            }
        }
    }

    // ----------------------------------------------------------------------------------------------
    // tags and anchors

    private function shortTag(string $tag): string
    {
        if (str_starts_with($tag, self::TAG_PREFIX)) {
            return '!!' . substr($tag, \strlen(self::TAG_PREFIX));
        }

        return $tag;
    }

    private function tagText(string $tag): string
    {
        $short = $this->shortTag($tag);

        return str_starts_with($short, '!') ? $short : '!<' . $short . '>';
    }

    private function writeTag(string $tag): void
    {
        if (!$this->whitespace) {
            $this->put(' ');
        }

        $this->put($tag);
        $this->whitespace = false;
        $this->indention  = false;
    }

    private function writeAnchor(string $indicator, string $name): void
    {
        if (!$this->whitespace) {
            $this->put(' ');
        }

        $this->put($indicator . $name, self::COLOR_ANCHOR);
        $this->whitespace = false;
        $this->indention  = false;
    }

    // ----------------------------------------------------------------------------------------------
    // comments

    /**
     * @param list<string> $comments
     */
    private function writeHeadComments(array $comments): void
    {
        $text = implode("\n", array_filter($comments, static fn (string $c): bool => '' !== $c));
        if ('' !== $text) {
            $this->writeIndent();
            $this->writeComment($text);
        }
    }

    /**
     * @param list<string> $comments
     */
    private function writeFootComments(array $comments): void
    {
        foreach ($comments as $comment) {
            if ('' !== $comment) {
                $this->writeIndent();
                $this->writeComment($comment);
            }
        }
    }

    private function writeLineComment(string $comment): void
    {
        if (!$this->whitespace) {
            $this->put(' ');
        }

        $this->writeComment($comment);
    }

    private function writeComment(string $comment): void
    {
        $lines = explode("\n", $comment);
        $last  = \count($lines) - 1;
        foreach ($lines as $i => $line) {
            if ('' !== $line) {
                if ($i > 0) {
                    $this->writeIndent();
                }

                $this->put('#' === $line[0] ? $line : '# ' . $line, self::COLOR_COMMENT);
                $this->indention = false;
            }

            if ($i < $last) {
                $this->putBreak();
                $this->indention = true;
            } elseif ('' !== $line) {
                $this->putBreak();
            }
        }

        $this->whitespace = true;
        $this->indention  = true;
    }

    // ----------------------------------------------------------------------------------------------
    // emitter primitives

    private function increaseIndent(): void
    {
        $this->indent = $this->indent < 0 ? 0 : $this->indent + $this->options->indent;
    }

    private function writeIndent(): void
    {
        $indent = max($this->indent, 0);
        if (!$this->indention || $this->column > $indent || ($this->column === $indent && !$this->whitespace)) {
            $this->putBreak();
        }

        if ($this->column < $indent) {
            $this->out .= str_repeat(' ', $indent - $this->column);
            $this->column = $indent;
        }

        $this->whitespace = true;
        $this->indention  = true;
    }

    private function indicator(string $text, bool $needWhitespace, bool $isWhitespace, bool $isIndention, ?int $color = null): void
    {
        if ($needWhitespace && !$this->whitespace) {
            $this->put(' ');
        }

        $this->put($text, $color);
        $this->whitespace = $isWhitespace;
        $this->indention  = $this->indention && $isIndention;
    }

    private function putBreak(): void
    {
        $this->out .= "\n";
        $this->column = 0;
    }

    private function put(string $text, ?int $color = null): void
    {
        $this->column += \strlen($text);
        if (null !== $color && $this->options->colors) {
            $this->out .= "\x1b[" . $color . 'm' . $text . "\x1b[0m";

            return;
        }

        $this->out .= $text;
    }
}
