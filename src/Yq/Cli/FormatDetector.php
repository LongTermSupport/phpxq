<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LTS\PhpXq\Yq\Format\FormatEnum;

/**
 * Chooses a format from a file name's extension, as the reference does for `-p auto` / `-o auto`.
 * Unknown extensions (and standard input) are YAML.
 */
final readonly class FormatDetector
{
    public function fromFilename(string $filename): FormatEnum
    {
        $dot = strrpos($filename, '.');
        if (false === $dot) {
            return FormatEnum::Yaml;
        }

        return match (strtolower(substr($filename, $dot + 1))) {
            'json'                => FormatEnum::Json,
            'properties', 'props' => FormatEnum::Props,
            'csv'                 => FormatEnum::Csv,
            'tsv'                 => FormatEnum::Tsv,
            'xml'                 => FormatEnum::Xml,
            'toml'                => FormatEnum::Toml,
            'hcl', 'tf'           => FormatEnum::Hcl,
            'lua'                 => FormatEnum::Lua,
            'kyaml'               => FormatEnum::Kyaml,
            'b64', 'base64'       => FormatEnum::Base64,
            default               => FormatEnum::Yaml,
        };
    }
}
