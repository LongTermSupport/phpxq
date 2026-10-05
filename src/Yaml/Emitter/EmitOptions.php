<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Emitter;

/**
 * How the YAML emitter renders a node. Defaults match the reference yq's defaults.
 */
final readonly class EmitOptions
{
    /**
     * @param int  $indent                spaces per level (yq `-I`, default 2)
     * @param bool $colors                ANSI colours (yq `-C`); false is yq `-M`
     * @param bool $unwrapScalar          print a top-level scalar bare, without quotes (yq default true)
     * @param bool $prettyPrint           normalise all styles to block/plain (yq `-P`), keeping comments
     * @param bool $noDocSeparator        never print `---` between documents (yq `--no-doc`)
     */
    public function __construct(
        public int $indent = 2,
        public bool $colors = false,
        public bool $unwrapScalar = true,
        public bool $prettyPrint = false,
        public bool $noDocSeparator = false,
    ) {
    }
}
