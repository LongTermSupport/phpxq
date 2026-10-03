<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\NodeKind;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * URI output: the scalar's text escaped like Go's url.QueryEscape.
 */
final class UriEncoder implements EncoderInterface
{
    public function format(): Format
    {
        return Format::Uri;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $scalar = NodeTools::unwrap($node);
        if (NodeKind::Scalar !== $scalar->kind) {
            throw new FormatException('cannot encode ' . $scalar->tag . ' as uri, can only operate on scalars');
        }

        return StringFormats::uriEncode($scalar->value) . "\n";
    }
}
