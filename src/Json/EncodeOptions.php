<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

/**
 * Output options of the JSON encoder, mirroring jq's dump flags.
 *
 * @api
 */
final readonly class EncodeOptions
{
    /**
     * @param int          $indent   spaces per level; 0 means compact (-c), jq's default is 2
     * @param bool         $useTab   indent with one tab per level (--tab); overrides $indent
     * @param bool         $sortKeys -S
     * @param bool         $ascii    -a: escape every non-ASCII codepoint as \uXXXX
     * @param ?ColorScheme $colors   null disables colour
     */
    public function __construct(
        public int $indent = 2,
        public bool $useTab = false,
        public bool $sortKeys = false,
        public bool $ascii = false,
        public ?ColorScheme $colors = null,
    ) {
    }

    public static function compact(): self
    {
        return new self(indent: 0);
    }
}
