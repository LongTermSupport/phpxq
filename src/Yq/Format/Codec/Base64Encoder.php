<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKindEnum;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * base64 and base64url output. Only string scalars can be encoded, as in the reference; pipe other values
 * through another encoder first.
 *
 * @internal
 */
final readonly class Base64Encoder implements EncoderInterface
{
    public function __construct(private FormatEnum $format = FormatEnum::Base64)
    {
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $scalar = NodeTools::unwrap($node);
        if (NodeKindEnum::Scalar !== $scalar->kind || CoreSchema::TAG_STR !== $scalar->tag) {
            throw new FormatException('cannot encode ' . ('' === $scalar->tag ? $scalar->kind->name : $scalar->tag) . ' as ' . $this->format->value . ', can only operate on strings. Please first pipe through another encoding operator to convert the value to a string');
        }

        $encoded = FormatEnum::Base64Url === $this->format ? StringFormats::base64UrlEncode($scalar->value) : StringFormats::base64Encode($scalar->value);

        return $encoded . "\n";
    }
}
