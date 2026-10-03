<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Emitter;

use LogicException;
use LTS\PhpXq\Yaml\Node;

/**
 * Owned by the YAML emitter worker (Plan 00004 architecture.md, file ownership map). Skeleton only.
 */
final class YamlEmitter implements YamlEmitterInterface
{
    public function emit(Node $node, EmitOptions $options = new EmitOptions()): string
    {
        throw new LogicException('YamlEmitter is not implemented');
    }

    public function emitStream(iterable $nodes, EmitOptions $options = new EmitOptions()): string
    {
        throw new LogicException('YamlEmitter is not implemented');
    }
}
