<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

/**
 * One element while the XML reader builds the tree: its text pieces, its children grouped by name in
 * first-seen order (attributes, processing instructions and directives are children too) and the comments
 * that surrounded it. `prefixes` maps namespace prefixes to URIs and is inherited from the parent.
 *
 * @internal
 */
final class XmlElement
{
    /** @var array<int|string, list<XmlElement>> */
    public array $children = [];

    /** @var list<string> */
    public array $data = [];

    public string $headComment = '';

    public string $lineComment = '';

    public string $footComment = '';

    public ElementStateEnum $state = ElementStateEnum::Started;

    public ?self $lastChild = null;

    /** @var array<string, string> */
    public array $prefixes = [];

    public string $defaultNamespace = '';

    public function __construct(public readonly string $rawName = '', public readonly ?self $parent = null)
    {
        if ($parent instanceof self) {
            $this->prefixes         = $parent->prefixes;
            $this->defaultNamespace = $parent->defaultNamespace;
        }
    }

    public function addChild(string $key, self $child): void
    {
        $this->children[$key][] = $child;
    }

    /**
     * A childless element holding one text value (an attribute, processing instruction or directive).
     */
    public static function leaf(string $rawName, string $text): self
    {
        $leaf       = new self($rawName);
        $leaf->data = [$text];

        return $leaf;
    }
}
