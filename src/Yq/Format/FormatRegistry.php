<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format;

use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Emitter\YamlEmitterInterface;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yaml\Parser\YamlParserInterface;
use LTS\PhpXq\Yq\Format\Codec\Base64Decoder;
use LTS\PhpXq\Yq\Format\Codec\Base64Encoder;
use LTS\PhpXq\Yq\Format\Codec\CsvDecoder;
use LTS\PhpXq\Yq\Format\Codec\CsvEncoder;
use LTS\PhpXq\Yq\Format\Codec\HclDecoder;
use LTS\PhpXq\Yq\Format\Codec\HclEncoder;
use LTS\PhpXq\Yq\Format\Codec\JsonDecoder;
use LTS\PhpXq\Yq\Format\Codec\JsonEncoder;
use LTS\PhpXq\Yq\Format\Codec\KyamlEncoder;
use LTS\PhpXq\Yq\Format\Codec\LuaDecoder;
use LTS\PhpXq\Yq\Format\Codec\LuaEncoder;
use LTS\PhpXq\Yq\Format\Codec\PropsDecoder;
use LTS\PhpXq\Yq\Format\Codec\PropsEncoder;
use LTS\PhpXq\Yq\Format\Codec\ShellEncoder;
use LTS\PhpXq\Yq\Format\Codec\TomlDecoder;
use LTS\PhpXq\Yq\Format\Codec\TomlEncoder;
use LTS\PhpXq\Yq\Format\Codec\UriDecoder;
use LTS\PhpXq\Yq\Format\Codec\UriEncoder;
use LTS\PhpXq\Yq\Format\Codec\XmlDecoder;
use LTS\PhpXq\Yq\Format\Codec\XmlEncoder;
use LTS\PhpXq\Yq\Format\Codec\YamlDecoder;
use LTS\PhpXq\Yq\Format\Codec\YamlEncoder;

/**
 * Finds the codec for a format, building each one on first use and keeping it. Every format has an
 * encoder; shell and kyaml have no decoder. Lua is read as well as written even though
 * {@see Format::canDecode()} says otherwise, because the reference reads Lua tables.
 */
final class FormatRegistry implements FormatRegistryInterface
{
    /** @var array<string, DecoderInterface> */
    private array $decoders = [];

    /** @var array<string, EncoderInterface> */
    private array $encoders = [];

    public function __construct(
        private readonly YamlParserInterface $yamlParser = new YamlParser(),
        private readonly YamlEmitterInterface $yamlEmitter = new YamlEmitter(),
    ) {
    }

    /**
     * The format a file name implies by its extension (the reference's `auto` input format), or null when
     * the extension says nothing.
     */
    public static function fromFilename(string $filename): ?Format
    {
        $dot = strrpos($filename, '.');
        if (false === $dot) {
            return null;
        }

        return match (strtolower(substr($filename, $dot + 1))) {
            'yaml', 'yml'        => Format::Yaml,
            'json'               => Format::Json,
            'xml'                => Format::Xml,
            'properties', 'props' => Format::Props,
            'csv'                => Format::Csv,
            'tsv'                => Format::Tsv,
            'toml'               => Format::Toml,
            'hcl', 'tf', 'tfvars' => Format::Hcl,
            'lua'                => Format::Lua,
            default              => null,
        };
    }

    public function decoder(Format $format): DecoderInterface
    {
        return $this->decoders[$format->value] ??= match ($format) {
            Format::Yaml      => new YamlDecoder($this->yamlParser),
            Format::Json      => new JsonDecoder(),
            Format::Props     => new PropsDecoder(),
            Format::Csv       => new CsvDecoder(Format::Csv, $this->yamlParser),
            Format::Tsv       => new CsvDecoder(Format::Tsv, $this->yamlParser),
            Format::Xml       => new XmlDecoder(),
            Format::Toml      => new TomlDecoder(),
            Format::Base64    => new Base64Decoder(Format::Base64),
            Format::Base64Url => new Base64Decoder(Format::Base64Url),
            Format::Uri       => new UriDecoder(),
            Format::Lua       => new LuaDecoder(),
            Format::Hcl       => new HclDecoder(),
            Format::Shell, Format::Kyaml => throw new FormatException('cannot read ' . $format->value . ' input; it is an output only format'),
        };
    }

    public function encoder(Format $format): EncoderInterface
    {
        return $this->encoders[$format->value] ??= match ($format) {
            Format::Yaml      => new YamlEncoder($this->yamlEmitter),
            Format::Json      => new JsonEncoder(),
            Format::Props     => new PropsEncoder(),
            Format::Csv       => new CsvEncoder(Format::Csv),
            Format::Tsv       => new CsvEncoder(Format::Tsv),
            Format::Xml       => new XmlEncoder(),
            Format::Toml      => new TomlEncoder(),
            Format::Base64    => new Base64Encoder(Format::Base64),
            Format::Base64Url => new Base64Encoder(Format::Base64Url),
            Format::Uri       => new UriEncoder(),
            Format::Shell     => new ShellEncoder(),
            Format::Lua       => new LuaEncoder(),
            Format::Kyaml     => new KyamlEncoder(),
            Format::Hcl       => new HclEncoder(),
        };
    }
}
