<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use Generator;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * Reads JSON, including several concatenated values (JSON lines), one Document per value. Mappings keep
 * key order and numbers keep their source text, so `50.0` stays `50.0`.
 */
final class JsonDecoder implements DecoderInterface
{
    public function format(): Format
    {
        return Format::Json;
    }

    /**
     * @return Generator<int, Node>
     */
    public function decode(string $input, FormatOptions $options): Generator
    {
        $reader = new JsonReader($input);
        while (($value = $reader->next()) instanceof Node) {
            yield Node::document($value);
        }
    }
}
