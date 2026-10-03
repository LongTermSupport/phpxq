<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format;

/**
 * The data formats the reference yq reads and writes, valued with the name used by `-p`/`-o`.
 * Not every format is both readable and writable; see {@see self::canDecode()} and {@see self::canEncode()}.
 */
enum FormatEnum: string
{
    /**
     * Resolves a `-p`/`-o` argument, accepting the reference's short aliases (`y`, `j`, `p`, `c`, `t`, `x`,
     * `yml`, `properties`, `sh`, `ky`).
     */
    public static function fromName(string $name): ?self
    {
        return match ($name) {
            'yaml', 'y', 'yml'         => self::Yaml,
            'json', 'j'                => self::Json,
            'props', 'p', 'properties' => self::Props,
            'csv', 'c'                 => self::Csv,
            'tsv', 't'                 => self::Tsv,
            'xml', 'x'                 => self::Xml,
            'toml'                     => self::Toml,
            'base64'                   => self::Base64,
            'base64url'                => self::Base64Url,
            'uri'                      => self::Uri,
            'shell', 'sh'              => self::Shell,
            'lua', 'l'                 => self::Lua,
            'kyaml', 'ky'              => self::Kyaml,
            'hcl'                      => self::Hcl,
            default                    => null,
        };
    }

    public function canDecode(): bool
    {
        return match ($this) {
            self::Shell => false,
            default     => true,
        };
    }

    public function canEncode(): bool
    {
        return true;
    }

    case Yaml = 'yaml';

    case Json = 'json';

    case Props = 'props';

    case Csv = 'csv';

    case Tsv = 'tsv';

    case Xml = 'xml';

    case Toml = 'toml';

    case Base64 = 'base64';

    case Base64Url = 'base64url';

    case Uri = 'uri';

    case Shell = 'shell';

    case Lua = 'lua';

    case Kyaml = 'kyaml';

    case Hcl = 'hcl';
}
