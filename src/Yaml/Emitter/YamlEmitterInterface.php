<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Emitter;

use LTS\PhpXq\Yaml\Node;

/**
 * Renders nodes as YAML text, reproducing comments, styles, tags, anchors, aliases and key order.
 *
 * Contract:
 *  - emit() accepts any node (a Document, or a bare sequence, mapping, scalar or alias, because
 *    evaluator results are arbitrary nodes) and returns text ending in exactly one newline, except an
 *    unwrapped top-level scalar, which is its value plus one newline;
 *  - a Document with `explicitStart` prints `---`, with `explicitEnd` prints `...`, with `directives`
 *    prints them first;
 *  - emitStream() joins documents the way yq does: a `---` line between consecutive documents unless
 *    `noDocSeparator`;
 *  - the emitter never mutates its input.
 *
 * @api
 */
interface YamlEmitterInterface
{
    public function emit(Node $node, EmitOptions $options = new EmitOptions()): string;

    /**
     * @param iterable<Node> $nodes
     */
    public function emitStream(iterable $nodes, EmitOptions $options = new EmitOptions()): string;
}
