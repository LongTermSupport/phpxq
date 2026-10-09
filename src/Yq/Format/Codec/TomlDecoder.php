<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * TOML input: one Document holding the table tree (see {@see TomlParser} for the node shapes).
 *
 * @internal
 */
final readonly class TomlDecoder implements DecoderInterface
{
    public function format(): FormatEnum
    {
        return FormatEnum::Toml;
    }

    /**
     * @return iterable<Node>
     */
    public function decode(string $input, FormatOptions $options): iterable
    {
        if ('' === trim($input)) {
            return;
        }

        yield new TomlParser($input)->parse();
    }
}
