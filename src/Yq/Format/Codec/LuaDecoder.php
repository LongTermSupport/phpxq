<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * Lua input: a `return { ... }` table or a list of global assignments, read as data (see {@see LuaReader}).
 *
 * @internal
 */
final readonly class LuaDecoder implements DecoderInterface
{
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
