<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Schema\CoreSchema;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * URI-escaped input: the unescaped text becomes one string scalar (a trailing newline is dropped).
 *
 * @internal
 */
final readonly class UriDecoder implements DecoderInterface
{
    public function format(): FormatEnum
    {
        return FormatEnum::Uri;
    }

    /**
     * @return iterable<Node>
     */
    public function decode(string $input, FormatOptions $options): iterable
    {
        yield Node::document(Node::scalar(StringFormats::uriDecode(rtrim($input, "\r\n")), CoreSchema::TAG_STR));
    }
}
