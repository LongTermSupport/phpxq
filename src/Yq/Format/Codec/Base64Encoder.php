<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * base64 and base64url output. Only string scalars can be encoded, as in the reference; pipe other values
 * through another encoder first.
 */
final readonly class Base64Encoder implements EncoderInterface
{
    public function __construct(private Format $format = Format::Base64)
    {
    }

    public function format(): Format
    {
        return $this->format;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $scalar = NodeTools::unwrap($node);
        if (NodeKind::Scalar !== $scalar->kind || CoreSchema::TAG_STR !== $scalar->tag) {
            throw new FormatException('cannot encode ' . ('' === $scalar->tag ? $scalar->kind->name : $scalar->tag) . ' as ' . $this->format->value . ', can only operate on strings. Please first pipe through another encoding operator to convert the value to a string');
        }

        $encoded = Format::Base64Url === $this->format ? StringFormats::base64UrlEncode($scalar->value) : StringFormats::base64Encode($scalar->value);

        return $encoded . "\n";
    }
}
