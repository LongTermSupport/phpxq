<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * base64 and base64url input: the decoded bytes become one string scalar. Whitespace around and inside
 * the text is ignored.
 *
 * @internal
 */
final readonly class Base64Decoder implements DecoderInterface
{
    public function __construct(private FormatEnum $format = FormatEnum::Base64)
    {
    }

    /**
     * @return iterable<Node>
     */
    public function decode(string $input, FormatOptions $options): iterable
    {
        $text = FormatEnum::Base64Url === $this->format ? StringFormats::base64UrlDecode($input) : StringFormats::base64Decode($input);

        yield Node::document(Node::scalar($text, CoreSchema::TAG_STR));
    }
}
