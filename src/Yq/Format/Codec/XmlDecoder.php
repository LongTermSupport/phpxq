<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * XML input shaped as the reference shapes it: an element becomes a key; repeated siblings become an array;
 * attributes become `+@name` children (first); text next to children or attributes becomes `+content`;
 * processing instructions become `+p_target` and directives `+directive`; every value is a string.
 * Comments become head, line and foot comments.
 */
final readonly class XmlDecoder implements DecoderInterface
{
    public function format(): FormatEnum
    {
        return FormatEnum::Xml;
    }

    /**
     * @return iterable<Node>
     */
    public function decode(string $input, FormatOptions $options): iterable
    {
        if ('' === trim($input)) {
            return;
        }

        $root = new XmlReader($input, $options)->read();

        yield Node::document($this->value($root, $options));
    }

    private function value(XmlElement $element, FormatOptions $options): Node
    {
        if ([] !== $element->children) {
            return $this->mapping($element, $options);
        }

        $node              = $this->dataNode(...$element->data);
        $node->headComment = $this->comment($element->headComment);
        $node->lineComment = $this->comment($element->lineComment);
        $node->footComment = $this->comment($element->footComment);

        return $node;
    }

    private function mapping(XmlElement $element, FormatOptions $options): Node
    {
        $content      = [];
        $headConsumed = false;
        if ([] !== $element->data) {
            $label              = Node::scalar($options->xmlContentName, CoreSchema::TAG_STR);
            $label->headComment = $this->comment($element->headComment);
            $label->lineComment = $this->comment($element->lineComment);
            $content[]          = $label;
            $content[]          = $this->dataNode(...$element->data);
            $headConsumed       = true;
        }

        $position = 0;
        foreach ($element->children as $name => $group) {
            ++$position;
            $key = Node::scalar((string)$name, CoreSchema::TAG_STR);
            if (1 === $position && !$headConsumed) {
                $key->headComment = $this->comment($element->headComment);
            }

            $content[] = $key;
            if (1 === \count($group)) {
                $content[] = $this->single($key, $group[0], $options);
                $foot      = $this->comment($group[0]->footComment);
            } else {
                $content[] = $this->sequence($options, ...$group);
                $foot      = $this->comment($group[\count($group) - 1]->footComment);
            }

            $key->footComment = NodeTools::joinComments($key->footComment, $foot);
        }

        return Node::mapping($content);
    }

    /**
     * The value of a key with exactly one child element; the element's head comment moves onto the key so
     * it prints above the whole `key: value` entry. Foot comments are placed by the caller.
     */
    private function single(Node $key, XmlElement $child, FormatOptions $options): Node
    {
        $node = $this->value($child, $options);

        $key->headComment  = NodeTools::joinComments($key->headComment, $node->headComment);
        $node->headComment = '';
        $node->footComment = '';
        if (NodeKindEnum::Mapping === $node->kind) {
            $node->lineComment = '';
        }

        return $node;
    }

    private function sequence(FormatOptions $options, XmlElement ...$group): Node
    {
        $items = [];
        $last  = \count($group) - 1;
        foreach ($group as $position => $child) {
            $item = $this->value($child, $options);
            if (NodeKindEnum::Mapping === $item->kind) {
                $item->footComment = $this->comment($child->footComment);
            }

            if ($position === $last) {
                $item->footComment = '';
            }

            $items[] = $item;
        }

        return Node::sequence($items);
    }

    private function dataNode(string ...$data): Node
    {
        if ([] === $data) {
            return Node::scalar('', CoreSchema::TAG_NULL);
        }

        if (1 === \count($data)) {
            return Node::scalar($data[0], CoreSchema::TAG_STR);
        }

        $items = [];
        foreach ($data as $piece) {
            $items[] = Node::scalar($piece, CoreSchema::TAG_STR);
        }

        return Node::sequence($items);
    }

    private function comment(string $raw): string
    {
        $text = trim($raw);
        if ('' === $text) {
            return '';
        }

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $lines[] = '# ' . $line;
        }

        return implode("\n", $lines);
    }
}
