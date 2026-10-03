<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Parser;

use Generator;
use LTS\PhpXq\Yaml\Node;

/**
 * Parses a YAML 1.2 stream into Document nodes the way go-yaml v3 does for the reference yq: the same
 * accepted syntax, the same errors, the same tags for plain scalars and the same comment placement.
 * The work is done by {@see StreamParser} on top of {@see \LTS\PhpXq\Yaml\Token\Scanner}.
 *
 * Notes on the Node model:
 *  - a plain scalar is tagged by {@see ScalarResolver}, which follows go-yaml (so it also yields
 *    !!timestamp and the !!merge tag of a plain `<<`), not the narrower CoreSchema;
 *  - an explicit non-specific tag `!` is kept as tag `!` with tagExplicit false;
 *  - `directives` holds the `%YAML` and `%TAG` lines of an explicit document, joined by newlines;
 *  - anchors stay visible to aliases in later documents of the same stream;
 *  - a stream that holds only comments yields one document whose null root carries them.
 */
final class YamlParser implements YamlParserInterface
{
    /**
     * @return Generator<int, Node>
     */
    public function parse(string $yaml): Generator
    {
        yield from (new StreamParser($yaml))->documents();
    }
}
