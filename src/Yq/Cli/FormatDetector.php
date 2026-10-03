<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

use LTS\PhpXq\Yq\Format\Format;

/**
 * Chooses a format from a file name's extension, as the reference does for `-p auto` / `-o auto`.
 * Unknown extensions (and standard input) are YAML.
 */
final class FormatDetector
{
    public function fromFilename(string $filename): Format
    {
        $dot = strrpos($filename, '.');
        if (false === $dot) {
            return Format::Yaml;
        }

        return match (strtolower(substr($filename, $dot + 1))) {
            'json'               => Format::Json,
            'properties', 'props' => Format::Props,
            'csv'                => Format::Csv,
            'tsv'                => Format::Tsv,
            'xml'                => Format::Xml,
            'toml'               => Format::Toml,
            'hcl', 'tf'          => Format::Hcl,
            'lua'                => Format::Lua,
            'kyaml'              => Format::Kyaml,
            'b64', 'base64'      => Format::Base64,
            default              => Format::Yaml,
        };
    }
}
