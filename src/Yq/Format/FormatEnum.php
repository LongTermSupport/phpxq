<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format;

/**
 * The data formats the reference yq reads and writes, valued with the name used by `-p`/`-o`.
 * ALIASES maps the reference's short `-p`/`-o` aliases to their format.
 * Not every format is both readable and writable; see {@see self::canDecode()} and {@see self::canEncode()}.
 *
 * @api
 */
enum FormatEnum: string
{
    private const array ALIASES = [
        'y'          => self::Yaml,
        'yml'        => self::Yaml,
        'j'          => self::Json,
        'p'          => self::Props,
        'properties' => self::Props,
        'c'          => self::Csv,
        't'          => self::Tsv,
        'x'          => self::Xml,
        'sh'         => self::Shell,
        'l'          => self::Lua,
        'ky'         => self::Kyaml,
    ];

    /**
     * Resolves a `-p`/`-o` argument: a format's own name or one of the reference's short aliases.
     */
    public static function fromName(string $name): ?self
    {
        return self::tryFrom($name) ?? self::ALIASES[$name] ?? null;
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
