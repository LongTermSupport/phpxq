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
final class XmlEncoder implements EncoderInterface
{
    private const int MAX_DEPTH = 1000;

    public function format(): FormatEnum
    {
        return FormatEnum::Xml;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $root = NodeTools::unwrap($node);
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
                $writer->start($name);
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

            $attributes .= ' ' . substr($keyName, \strlen($options->xmlAttributePrefix)) . '="' . $this->escapeAttribute($attribute->value) . '"';
        }

        $writer->start($name, $attributes);
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

    private function procInst(string $key, Node $value): string
    {
        $text = NodeTools::unwrap($value);
        $body = substr($key, \strlen(XmlReader::PROC_INST_PREFIX));

        return '<?' . $body . ('' === $text->value ? '' : ' ' . $text->value) . '?>';
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
