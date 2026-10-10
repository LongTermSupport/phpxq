<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format;

use LTS\PhpXq\Yaml\Node;

/**
 * Writes one result node in one format.
 *
 * `$resultIndex` is the 0-based position of this node in the output stream, so stream-aware formats can
 * act on it: YAML prints `---` before every result after the first, CSV and TSV print their header only
 * for index 0 and expect later results to share it. The returned text includes its trailing newline.
 * The encoder never mutates the node.
 *
 * @internal
 */
interface EncoderInterface
{
    /**
     * @throws FormatException
     */
    public function encode(Node $node, FormatOptions $options, int $resultIndex): string;
}
