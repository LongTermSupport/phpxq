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

    /** The XML 1.0 (fifth edition) `Name` production: a NameStartChar, then NameChars. */
    private const string XML_NAME = '/^[:A-Z_a-z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}][:A-Z_a-z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}\-.0-9\x{B7}\x{300}-\x{36F}\x{203F}-\x{2040}]*$/Du';

    /** What ends a comment early; XML forbids it anywhere inside one. */
    private const string COMMENT_END = '--';

    /** What ends a processing instruction early. */
    private const string PROC_INST_END = '?>';

    public function format(): FormatEnum
    {
        return FormatEnum::Xml;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::expandableRoot($node);
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
        foreach (NodeTools::pairs($map) as [$key, $value]) {
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
        $pairs      = NodeTools::pairs($map);
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
        $target = $this->name(substr($key, \strlen(XmlReader::PROC_INST_PREFIX)));
        if (str_contains($text->value, self::PROC_INST_END)) {
            throw new FormatException('xml: a processing instruction cannot contain ' . self::PROC_INST_END);
        }

        return '<?' . $target . ('' === $text->value ? '' : ' ' . $text->value) . self::PROC_INST_END;
    }

    /**
     * @throws FormatException when the name is not an XML name, which would let a key write markup
     */
    private function name(string $name): string
    {
        if (1 !== preg_match(self::XML_NAME, $name)) {
            throw new FormatException(\sprintf('xml: %s is not a valid XML name', json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)));
        }

        return $name;
    }

    private function directive(Node $value): string
    {
        return '<!' . NodeTools::unwrap($value)->value . '>';
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
            throw new FormatException('xml: a comment cannot contain ' . self::COMMENT_END);
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
