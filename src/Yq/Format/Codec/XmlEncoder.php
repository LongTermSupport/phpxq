<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * XML output. Keys become elements, arrays repeat their element, `+@name` keys become attributes,
 * `+content` is the element text, `+p_target` and `+directive` write processing instructions and
 * directives, and comments are written as XML comments around the entry they belong to. Indentation is
 * `-I` spaces (none at 0).
 */
final readonly class XmlEncoder implements EncoderInterface
{
    /** The deepest element nesting written; deeper usually means an alias cycle. */
    private const int MAX_DEPTH = 1000;

    /** The XML 1.0 (fifth edition) `Name` production, which processing-instruction targets must match. */
    private const string XML_NAME = '/^[:A-Z_a-z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}][:A-Z_a-z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}\-.0-9\x{B7}\x{300}-\x{36F}\x{203F}-\x{2040}]*$/Du';

    /**
     * Characters that would let an element or attribute name close its tag, open another, start an attribute value
     * or an entity; any other text is written as the reference writes it.
     */
    private const string NAME_MARKUP = '<>&"\'=/!?';

    /** What opens a comment. */
    private const string COMMENT_START = '<!--';

    /** What ends a comment early. */
    private const string COMMENT_END = '-->';

    /** What ends a processing instruction early. */
    private const string PROC_INST_END = '?>';

    /** The reference's refusal of a directive whose `<` and `>` do not balance outside quotes and comments. */
    private const string BAD_DIRECTIVE = 'xml: EncodeToken of Directive containing wrong < or > markers';

    /** The reference's refusal of a processing-instruction target that is not an XML name. */
    private const string BAD_TARGET = 'xml: EncodeToken of ProcInst with invalid Target';

    /** The reference's refusal of processing-instruction text that would end it early. */
    private const string BAD_PROC_INST = 'xml: EncodeToken of ProcInst containing ?> marker';

    /** The reference's refusal of comment text that would end it early. */
    private const string BAD_COMMENT = 'xml: EncodeToken of Comment containing --> marker';

    /** The reference's refusal of an empty element or attribute name. */
    private const string NO_NAME = 'xml: start tag with no name';

    public function format(): FormatEnum
    {
        return FormatEnum::Xml;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::expandableRoot($node, $options->yamlFixMergeAnchorToSpec);
        if (NodeKindEnum::Scalar === $root->kind) {
            return $this->escapeText($root->value) . "\n";
        }

        $writer = new XmlWriter($options->indent > 0 ? str_repeat(' ', $options->indent) : '');
        $roots  = NodeKindEnum::Sequence === $root->kind ? $root->content : [$root];
        foreach ($roots as $item) {
            $item = NodeTools::unwrap($item);
            if (NodeKindEnum::Mapping !== $item->kind) {
                throw new FormatException('xml: the top level must be a map or an array of maps');
            }

            $this->topLevel($writer, $item, $options);
        }

        $out = $writer->result();

        return '' === $out ? '' : $out . "\n";
    }

    private function topLevel(XmlWriter $writer, Node $map, FormatOptions $options): void
    {
        foreach (NodeTools::pairs($map, $options->yamlFixMergeAnchorToSpec) as [$key, $value]) {
            $name = NodeTools::keyText($key);
            foreach ([$key->headComment, $key->lineComment] as $raw) {
                $comment = $this->comment($raw);
                if ('' !== $comment) {
                    $writer->raw($comment . "\n");
                }
            }

            if (str_starts_with($name, XmlReader::PROC_INST_PREFIX)) {
                $writer->raw($this->procInst($name, $value) . "\n");
            } elseif (XmlReader::DIRECTIVE_NAME === $name) {
                $writer->raw($this->directive($value) . "\n");
            } else {
                $this->element($writer, $value, $name, $options, 0);
            }

            $writer->raw($this->comment($key->footComment));
        }
    }

    private function element(XmlWriter $writer, Node $value, string $name, FormatOptions $options, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormatException('xml: exceeded max depth (alias cycle?)');
        }

        $value = NodeTools::unwrap($value);
        switch ($value->kind) {
            case NodeKindEnum::Sequence:
                foreach ($value->content as $item) {
                    $this->element($writer, $item, $name, $options, $depth + 1);
                }

                return;
            case NodeKindEnum::Mapping:
                $this->mapping($writer, $value, $name, $options, $depth);

                return;
            default:
                $writer->start($this->name($name));
                $writer->raw($this->comment($value->headComment));
                $writer->raw($this->escapeText($value->value));
                $writer->raw($this->comment($value->lineComment));
                $writer->end($name);
                $writer->raw($this->comment($value->footComment));
        }
    }

    private function mapping(XmlWriter $writer, Node $map, string $name, FormatOptions $options, int $depth): void
    {
        $pairs      = NodeTools::pairs($map, $options->yamlFixMergeAnchorToSpec);
        $attributes = '';
        foreach ($pairs as [$key, $value]) {
            $keyName = NodeTools::keyText($key);
            if ('' === $options->xmlAttributePrefix || !str_starts_with($keyName, $options->xmlAttributePrefix)) {
                continue;
            }

            $attribute = NodeTools::unwrap($value);
            if (NodeKindEnum::Scalar !== $attribute->kind) {
                throw new FormatException('xml: cannot use ' . $attribute->tag . ' as attribute, only scalars are supported');
            }

            $attributes .= ' ' . $this->name(substr($keyName, \strlen($options->xmlAttributePrefix))) . '="' . $this->escapeAttribute($attribute->value) . '"';
        }

        $writer->start($this->name($name), $attributes);
        foreach ($pairs as [$key, $value]) {
            $keyName = NodeTools::keyText($key);
            $writer->raw($this->comment($key->headComment, $key->lineComment));
            if ('' !== $options->xmlAttributePrefix && str_starts_with($keyName, $options->xmlAttributePrefix)) {
                $writer->raw($this->comment($key->footComment));

                continue;
            }

            if (str_starts_with($keyName, XmlReader::PROC_INST_PREFIX)) {
                $writer->raw($this->procInst($keyName, $value));
            } elseif (XmlReader::DIRECTIVE_NAME === $keyName) {
                $writer->raw($this->directive($value));
            } elseif ($keyName === $options->xmlContentName) {
                $content = NodeTools::unwrap($value);
                if (NodeKindEnum::Scalar !== $content->kind) {
                    throw new FormatException('xml: ' . $keyName . ' must be a scalar');
                }

                $writer->raw($this->comment($content->headComment, $content->lineComment));
                $writer->raw($this->escapeText($content->value));
                $writer->raw($this->comment($content->footComment));
            } else {
                $this->element($writer, $value, $keyName, $options, $depth + 1);
            }

            $writer->raw($this->comment($key->footComment));
        }

        $writer->end($name);
        $writer->raw($this->comment($map->footComment));
    }

    /**
     * @throws FormatException when the target is not an XML name or the text would end the instruction early
     */
    private function procInst(string $key, Node $value): string
    {
        $text   = NodeTools::unwrap($value);
        $target = substr($key, \strlen(XmlReader::PROC_INST_PREFIX));
        if (1 !== preg_match(self::XML_NAME, $target)) {
            throw new FormatException(self::BAD_TARGET);
        }

        if (str_contains($text->value, self::PROC_INST_END)) {
            throw new FormatException(self::BAD_PROC_INST);
        }

        return '<?' . $target . ('' === $text->value ? '' : ' ' . $text->value) . self::PROC_INST_END;
    }

    /**
     * @throws FormatException when the name is empty (the reference's error) or holds a character that would let a
     *                         key write markup
     */
    private function name(string $name): string
    {
        if ('' === $name) {
            throw new FormatException(self::NO_NAME);
        }

        if (false !== strpbrk($name, self::NAME_MARKUP)) {
            throw new FormatException(\sprintf('xml: %s is not a valid XML name', json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)));
        }

        return $name;
    }

    /**
     * @throws FormatException when the directive's `<` and `>` do not balance, which would let it write markup
     */
    private function directive(Node $value): string
    {
        $text = NodeTools::unwrap($value)->value;
        if (!$this->isBalancedDirective($text)) {
            throw new FormatException(self::BAD_DIRECTIVE);
        }

        return '<!' . $text . '>';
    }

    /**
     * The reference's directive check: outside quotes and comments every `>` closes an earlier `<`, and nothing is
     * left open at the end.
     */
    private function isBalancedDirective(string $text): bool
    {
        $depth     = 0;
        $quote     = '';
        $inComment = false;
        for ($i = 0, $n = \strlen($text); $i < $n; ++$i) {
            $char = $text[$i];
            if ($inComment) {
                $inComment = !('>' === $char && $i >= 2 && self::COMMENT_END === substr($text, $i - 2, 3));
            } elseif ('' !== $quote) {
                $quote = $char === $quote ? '' : $quote;
            } elseif ("'" === $char || '"' === $char) {
                $quote = $char;
            } elseif ('<' === $char) {
                if ($i + \strlen(self::COMMENT_START) < $n && str_starts_with(substr($text, $i), self::COMMENT_START)) {
                    $inComment = true;
                } else {
                    ++$depth;
                }
            } elseif ('>' === $char) {
                if (0 === $depth) {
                    return false;
                }

                --$depth;
            }
        }

        return 0 === $depth && '' === $quote && !$inComment;
    }

    /**
     * An XML comment made from YAML comments, or '' when they hold no text. Text is padded with a space
     * on each side unless it starts or ends with a newline.
     */
    private function comment(string ...$yamlComments): string
    {
        $parts = [];
        foreach ($yamlComments as $yamlComment) {
            $text = NodeTools::commentText(str_ends_with($yamlComment, "\n") ? substr($yamlComment, 0, -1) : $yamlComment);
            if ('' !== $text) {
                $parts[] = $text;
            }
        }

        if ([] === $parts) {
            return '';
        }

        $text = implode(' ', $parts);
        if (str_contains($text, self::COMMENT_END)) {
            throw new FormatException(self::BAD_COMMENT);
        }

        return '<!--' . (str_starts_with($text, "\n") ? '' : ' ') . $text . (str_ends_with($text, "\n") ? '' : ' ') . '-->';
    }

    private function escapeText(string $text): string
    {
        return strtr($text, [
            '&'  => '&amp;',
            '<'  => '&lt;',
            '>'  => '&gt;',
            '"'  => '&#34;',
            "'"  => '&#39;',
            "\t" => '&#x9;',
            "\r" => '&#xD;',
        ]);
    }

    private function escapeAttribute(string $text): string
    {
        return strtr($text, [
            '&'  => '&amp;',
            '<'  => '&lt;',
            '>'  => '&gt;',
            '"'  => '&#34;',
            "'"  => '&#39;',
            "\t" => '&#x9;',
            "\n" => '&#xA;',
            "\r" => '&#xD;',
        ]);
    }
}
