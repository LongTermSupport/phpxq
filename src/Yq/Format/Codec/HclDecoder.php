<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * HCL input: one Document holding the attributes and nested blocks (see {@see HclReader}).
 */
final class HclDecoder implements DecoderInterface
{
    public function format(): Format
    {
        return Format::Hcl;
    }

    /**
     * @return iterable<Node>
     */
    public function decode(string $input, FormatOptions $options): iterable
    {
        if ('' === trim($input)) {
            return;
        }

        yield new HclReader($input)->read();
    }
}
