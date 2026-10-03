<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

/**
 * ANSI start sequences per value kind (the JQ_COLORS contract). Each field holds the full escape
 * sequence, for example "\e[0;32m"; the encoder appends "\e[0m" after each coloured token.
 *
 * @api
 */
final readonly class ColorScheme
{
    public function __construct(
        public string $null,
        public string $false,
        public string $true,
        public string $number,
        public string $string,
        public string $array,
        public string $object,
        public string $objectKey,
    ) {
    }

    /**
     * jq 1.8's default palette.
     */
    public static function default(): self
    {
        return new self(
            "\e[0;90m",
            "\e[0;39m",
            "\e[0;39m",
            "\e[0;39m",
            "\e[0;32m",
            "\e[1;39m",
            "\e[1;39m",
            "\e[1;34m",
        );
    }
}
