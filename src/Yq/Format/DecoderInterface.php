<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format;

use LTS\PhpXq\Yaml\Node;

/**
 * Reads text in one format into the shared node model.
 *
 * Every decoder yields Document nodes (kind Document, one root in `content`) so downstream code treats
 * all input alike. Formats with a single value per input (json, csv, props, xml, toml) yield one
 * document; a decoder may yield several for multi-document input (YAML, and JSON lines of concatenated
 * values). Scalars are tagged as the reference tags them (numbers !!int/!!float, booleans !!bool,
 * null !!null, everything else !!str).
 *
 * @api
 */
interface DecoderInterface
{
    public function format(): Format;

    /**
     * @return iterable<Node>
     *
     * @throws FormatException
     */
    public function decode(string $input, FormatOptions $options): iterable;
}
