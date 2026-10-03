<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * Lua input: a `return { ... }` table or a list of global assignments, read as data (see {@see LuaReader}).
 */
final class LuaDecoder implements DecoderInterface
{
    public function format(): FormatEnum
    {
        return FormatEnum::Lua;
    }

    /**
     * @return iterable<Node>
     */
    public function decode(string $input, FormatOptions $options): iterable
    {
        if ('' === trim($input)) {
            return;
        }

        yield Node::document(new LuaReader($input)->read());
    }
}
