<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Emitter;

use LogicException;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\NodeStyleEnum;
use LTS\PhpXq\Yaml\Parser\ScalarResolver;
use LTS\PhpXq\Yaml\Schema\CoreSchema;

/**
 * Writes one node tree as YAML text. A port of the go-yaml v3 / libyaml emitter state (column, indent,
 * whitespace and indention flags) driven directly by the Node tree, so indentation, quoting and comment
 * placement match the reference byte for byte. Lines are never wrapped (yq disables go-yaml's folding).
 *
 * Emitter state worth knowing: the indent step is 2 whenever libyaml would reject the option (outside 2..9);
 * a line comment stays pending until its node's line is finished; after a foot comment the next line at the
 * same indent is preceded by a blank line. RESERVED_WORDS are plain words the core schema resolves to
 * something other than a string; OLD_BOOLS are YAML 1.1 booleans that `-P` keeps quoted.
 *
 * @internal
 */
final class YamlWriter
{
    private const string TAG_NULL   = '!!null';

    private const string TAG_STR    = '!!str';

    private const int COLOR_KEY     = 36;

    private const int COLOR_STRING  = 32;

    private const int COLOR_NUMBER  = 95;

    private const int COLOR_ANCHOR  = 93;

    private const int COLOR_COMMENT = 90;

    private const string TAG_PREFIX = 'tag:yaml.org,2002:';

    private const string TIMESTAMP = '/\A[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}(?:(?:[Tt]|[ \t]+)[0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2}(?:\.[0-9]*)?(?:[ \t]*(?:Z|[-+][0-9]{1,2}(?::[0-9]{2})?))?)?\z/';

    private const string PLAIN_INT = '/\A(?:0|[1-9][0-9]{0,17})\z/';

    private const array BOOL_WORDS = ['true' => true, 'True' => true, 'TRUE' => true, 'false' => true, 'False' => true, 'FALSE' => true];

    private const string PLAIN_WORD ='/\A[A-Za-z_][A-Za-z0-9_ -]*+(?<! )\z/';

    private const string DOUBLE_QUOTE_SPECIALS = '/["\\\\\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\xA8\xA9]|\xEF\xBB\xBF|\xEF\xBF[\xBE\xBF]|[\xF0-\xF7][\x80-\xBF]{3}/';

    private const array RESERVED_WORDS = [
        'null'  => true, 'Null' => true, 'NULL' => true,
        'true'  => true, 'True' => true, 'TRUE' => true,
        'false' => true, 'False' => true, 'FALSE' => true,
    ];

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

    private string $pendingLineComment = '';

    private bool $inSequenceItem = false;

    private int $footIndent = -1;

    private int $flowLevel = 0;

    /** @var array<int, true> the collections being emitted right now, to refuse a structure that contains itself */
    private array $onPath = [];

    private readonly int $step;

    public function __construct(private readonly EmitOptions $options)
    {
        $this->step = $options->indent < 2 || $options->indent > 9 ? 2 : $options->indent;
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

        $this->emitNode($root, false, null, null, $block ? $root->lineComment : '', $block ? '' : $root->lineComment);
        $this->flushLineComment();

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
    private function emitNode(Node $node, bool $simpleKey, ?array $plan, ?int $color, string $headerComment, string $lineComment = ''): void
    {
        if ('' !== $lineComment) {
            $this->pendingLineComment = $lineComment;
        }

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

            default:
                $id = spl_object_id($node);
                if (isset($this->onPath[$id])) {
                    throw new LogicException('cannot encode a cyclic structure as yaml');
                }

                $this->onPath[$id] = true;
                if (NodeKindEnum::Document === $node->kind) {
                    $this->emitNode($node->root(), $simpleKey, null, $color, $headerComment, $lineComment);
                } else {
                    $this->emitCollection($node, $headerComment);
                }

                unset($this->onPath[$id]);
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

        // go-yaml writes a scalar value's head comment before the next key, unless that key has its own.
        $carry    = '';
        $head     = $mapping->headComment;
        $count    = \count($mapping->content);
        $fastOk   = !$this->options->colors;
        $pad      = '';
        $nextLine = false;
        for ($i = 0; $i < $count; $i += 2) {
            $key   = $mapping->content[$i];
            $value = $mapping->content[$i + 1] ?? new Node(NodeKindEnum::Scalar, self::TAG_NULL);

            // Fast path (benchmark yq:identity-medium): `key: value` where both are scalars that print plain.
            // After one such line the writer state is known (broken-off line, nothing pending), so the next
            // one is written as "\n" . indent . text without going through writeIndent().
            if ('' === $head && '' === $carry && $fastOk) {
                $keyText =$this->plainScalarText($key);
                if (null !== $keyText && \strlen($keyText) <= 128) {
                    $valueText = $this->plainScalarText($value);
                    if (null !== $valueText) {
                        $line = $keyText . ': ' . $valueText;
                        if ($nextLine) {
                            $this->out .= "\n" . $pad . $line;
                        } else {
                            $this->writeIndent();
                            $pad = str_repeat(' ', $this->column);
                            $this->out .= $line;
                        }

                        $this->column     = \strlen($pad) + \strlen($line);
                        $this->whitespace = false;
                        $this->indention  = false;
                        $nextLine         = true;

                        continue;
                    }

                    // Same fast path for a plain key that opens a block collection without comments: the key
                    // needs no scalar planning and no comment bookkeeping (benchmark yq:identity-medium).
                    if ('' === $value->headComment && '' === $value->footComment && '' === $value->lineComment && $this->isBlockCollection($value)) {
                        $this->writeIndent();
                        $this->out .= $keyText . ':';
                        $this->column += \strlen($keyText) + 1;
                        $this->whitespace = false;
                        $this->indention  = false;
                        $this->emitNode($value, false, null, null, '');
                        $nextLine = false;

                        continue;
                    }
                }
            }

            $nextLine = false;

            $valueBlock = $this->isBlockCollection($value);
            $heads      = [$head, '' !== $key->headComment ? $key->headComment : $carry];

            $head  = '';
            $carry = $valueBlock ? '' : $value->headComment;
            $this->writeHeadComments(...$heads);

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
            $this->emitNode($value, false, null, null, $valueBlock ? $lineComment : '', $valueBlock ? '' : $lineComment);
            $this->flushLineComment();

            $this->writeFootComments($key->footComment, $value->footComment, $i + 2 >= $count ? $carry : '');
        }

        $this->indent = $saved;
    }

    private function emitBlockSequence(Node $sequence): void
    {
        $saved = $this->indent;
        $this->increaseIndent();

        $head     = $sequence->headComment;
        $fastOk   = !$this->options->colors;
        $pad      = '';
        $nextLine = false;
        foreach ($sequence->content as $item) {
            // Fast path (benchmark yq:identity-medium): `- scalar` where the scalar prints plain; consecutive
            // lines skip writeIndent() as in emitBlockMapping().
            if ('' === $head && $fastOk) {
                $text = $this->plainScalarText($item);
                if (null !== $text) {
                    $line = '- ' . $text;
                    if ($nextLine) {
                        $this->out .= "\n" . $pad . $line;
                    } else {
                        $this->writeIndent();
                        $pad = str_repeat(' ', $this->column);
                        $this->out .= $line;
                    }

                    $this->column     = \strlen($pad) + \strlen($line);
                    $this->whitespace = false;
                    $this->indention  = false;
                    $nextLine         = true;

                    continue;
                }
            }

            $nextLine = false;
            $this->writeHeadComments($head, $item->headComment);
            $head = '';

            $this->writeIndent();
            $this->indicator('-', true, false, true);

            $itemBlock = $this->isBlockCollection($item);

            $this->inSequenceItem = true;
            $this->emitNode($item, false, null, null, $itemBlock ? $item->lineComment : '', $itemBlock ? '' : $item->lineComment);
            $this->inSequenceItem = false;
            $this->flushLineComment();

            $this->writeFootComments($item->footComment);
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
            $value = $mapping->content[$i + 1] ?? new Node(NodeKindEnum::Scalar, self::TAG_NULL);
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
     * The text of a scalar that certainly prints as a plain scalar with no tag, anchor or comment, or null
     * when the full planning in {@see self::planScalar()} is needed. Covers plain-looking strings, small
     * decimal integers and the booleans; every case is one planScalar() would also print plain.
     */
    private function plainScalarText(Node $node): ?string
    {
        if (
            NodeKindEnum::Scalar                                                                                                               !== $node->kind || NodeStyleEnum::Default !== $node->style || $node->tagExplicit
                                                                                                                                                               || '' !== $node->anchor || '' !== $node->headComment || '' !== $node->lineComment || '' !== $node->footComment
        ) {
            return null;
        }

        $value = $node->value;

        return match ($node->tag) {
            self::TAG_STR => 1      === preg_match(self::PLAIN_WORD, $value) && !isset(self::RESERVED_WORDS[$value]) ? $value : null,
            '!!int'       => 1      === preg_match(self::PLAIN_INT, $value) ? $value : null,
            '!!bool'      => isset(self::BOOL_WORDS[$value]) ? $value : null,
            default       => null,
        };
    }

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

        if ($this->options->prettyPrint && NodeStyleEnum::Default !== $style && NodeStyleEnum::Flow !== $style) {
            $style = isset(self::OLD_BOOLS[$value]) ? NodeStyleEnum::DoubleQuoted : NodeStyleEnum::Default;
        }

        if ('' !== $value && !mb_check_encoding($value, 'UTF-8')) {
            $encoded = base64_encode($value);
            $value   = implode("\n", str_split($encoded, 70));
            $stag    = '!!binary';
            $tag     = $stag;
            $style   = NodeStyleEnum::Default;
        }

        $quoted = NodeStyleEnum::DoubleQuoted                                                                                                                                                                                                                                                                                                                                                                                                                                                           === $style || NodeStyleEnum::SingleQuoted === $style
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   || NodeStyleEnum::Literal         === $style || NodeStyleEnum::Folded === $style;

        $force = false;
        if ('' !== $tag && !$node->tagExplicit) {
            if (self::TAG_STR === $stag && $quoted) {
                $tag = '';
            } elseif (self::TAG_STR === $stag && 1 === preg_match(self::PLAIN_WORD, $value) && !isset(self::RESERVED_WORDS[$value])) {
                $tag = '';
            } else {
                $resolved = $this->resolveImplicit($value);
                if ($resolved === $stag) {
                    $tag = '';
                } elseif (self::TAG_STR === $stag) {
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
        if (CoreSchema::TAG_INT === $tag && CoreSchema::TAG_INT !== ScalarResolver::resolve($value)) {
            // Decimal digits beyond 64 bits resolve as a float, as go-yaml does.
            $tag = ScalarResolver::resolve($value);
        }

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
                    $this->indent = $this->step;
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
            self::TAG_NULL               => null,
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
            $this->indicator((string)$this->step, false, false, false);
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

    /**
     * Ends the `|` / `>` header line: a pending line comment goes after the indicators (and ends the line
     * itself), otherwise the line is just broken.
     */
    private function endBlockScalarHeader(): void
    {
        if ('' === $this->pendingLineComment) {
            $this->putBreak();

            return;
        }

        $this->flushLineComment();
    }

    private function writeLiteral(string $value, ?int $color): void
    {
        $this->indicator('|', true, false, false);
        $this->writeBlockScalarHints($value);
        $this->endBlockScalarHeader();
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
        $this->endBlockScalarHeader();
        $this->indention  = true;
        $this->whitespace = true;

        // go-yaml measures the "next character is blank" test from the START of the value, not from the
        // newline being written, so every newline between non-blank text gets an extra blank line unless
        // the value itself begins with a blank.
        $lead       = ltrim($value, "\n\r");
        $extraBreak = '' !== $lead && !\in_array($lead[0], [' ', "\t"], true);

        $parts          = explode("\n", $value);
        $breaks         = true;
        $leadingSpaces  = true;
        foreach ($parts as $j => $part) {
            if ($j > 0) {
                if (!$breaks && !$leadingSpaces && $extraBreak) {
                    $this->putBreak();
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

    private function writeHeadComments(string ...$comments): void
    {
        $text = implode("\n", array_filter($comments, static fn (string $c): bool => '' !== $c));
        if ('' !== $text) {
            $this->writeIndent();
            $this->writeComment($text);
        }
    }

    private function writeFootComments(string ...$comments): void
    {
        foreach ($comments as $comment) {
            if ('' !== $comment) {
                $this->writeIndent();
                $this->writeComment($comment);
                $this->footIndent = max($this->indent, 0);
            }
        }
    }

    /**
     * Writes the line comment an emitted node left pending (block scalars write theirs beside the header).
     */
    private function flushLineComment(): void
    {
        if ('' !== $this->pendingLineComment) {
            $comment                  = $this->pendingLineComment;
            $this->pendingLineComment = '';
            $this->writeLineComment($comment);
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
        $inSequenceItem        = $this->inSequenceItem;
        $this->inSequenceItem  = false;

        if ($this->indent < 0) {
            $this->indent = 0;

            return;
        }

        $this->indent = $inSequenceItem
            ? $this->indent + 2
            : $this->step * intdiv($this->indent + $this->step, $this->step);
    }

    private function writeIndent(): void
    {
        $indent = max($this->indent, 0);
        if (!$this->indention || $this->column > $indent || ($this->column === $indent && !$this->whitespace)) {
            $this->putBreak();
        }

        if ($this->footIndent === $indent) {
            $this->putBreak();
        }

        if ($this->column < $indent) {
            $this->out .= str_repeat(' ', $indent - $this->column);
            $this->column = $indent;
        }

        $this->whitespace = true;
        $this->indention  = true;
        $this->footIndent = -1;
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
