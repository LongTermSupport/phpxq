<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format;

/**
 * Every per-format switch of the reference's command line, one flat value object so the CLI fills it once
 * and each codec reads only what it needs. Defaults match the reference's defaults.
 * `yamlFixMergeAnchorToSpec` is `--yaml-fix-merge-anchor-to-spec`, which also decides which `<<` values the
 * encoders merge (see {@see Codec\NodeTools::pairs()}).
 *
 * @internal
 */
final readonly class FormatOptions
{
    public function __construct(
        public int $indent = 2,
        public bool $colors = false,
        public bool $unwrapScalar = true,
        public bool $prettyPrint = false,
        public bool $noDocSeparator = false,
        public string $csvSeparator = ',',
        public string $tsvSeparator = "\t",
        public bool $csvAutoParse = true,
        public string $propertiesSeparator = ' = ',
        public bool $propertiesArrayBrackets = false,
        public string $xmlAttributePrefix = '+@',
        public string $xmlContentName = '+content',
        public bool $xmlSkipProcInst = false,
        public bool $xmlSkipDirectives = false,
        public bool $xmlKeepNamespace = true,
        public bool $xmlRawToken = true,
        public bool $xmlStrictMode = false,
        public string $shellKeySeparator = '_',
        public bool $luaUnquoted = false,
        public bool $luaGlobals = false,
        public bool $yamlFixMergeAnchorToSpec = false,
    ) {
    }
}
