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
 * encoder; shell has no decoder, and kyaml is read as YAML.
 *
 * @internal
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

    public function decoder(FormatEnum $format): DecoderInterface
    {
        return $this->decoders[$format->value] ??= match ($format) {
            FormatEnum::Yaml                     => new YamlDecoder($this->yamlParser),
            FormatEnum::Json                     => new JsonDecoder(),
            FormatEnum::Props                    => new PropsDecoder(),
            FormatEnum::Csv                      => new CsvDecoder(FormatEnum::Csv, $this->yamlParser),
            FormatEnum::Tsv                      => new CsvDecoder(FormatEnum::Tsv, $this->yamlParser),
            FormatEnum::Xml                      => new XmlDecoder(),
            FormatEnum::Toml                     => new TomlDecoder(),
            FormatEnum::Base64                   => new Base64Decoder(FormatEnum::Base64),
            FormatEnum::Base64Url                => new Base64Decoder(FormatEnum::Base64Url),
            FormatEnum::Uri                      => new UriDecoder(),
            FormatEnum::Lua                      => new LuaDecoder(),
            FormatEnum::Hcl                      => new HclDecoder(),
            FormatEnum::Kyaml                    => new YamlDecoder($this->yamlParser, FormatEnum::Kyaml),
            FormatEnum::Shell                    => throw new FormatException('cannot read ' . $format->value . ' input; it is an output only format'),
        };
    }

    public function encoder(FormatEnum $format): EncoderInterface
    {
        return $this->encoders[$format->value] ??= match ($format) {
            FormatEnum::Yaml      => new YamlEncoder($this->yamlEmitter),
            FormatEnum::Json      => new JsonEncoder(),
            FormatEnum::Props     => new PropsEncoder(),
            FormatEnum::Csv       => new CsvEncoder(FormatEnum::Csv),
            FormatEnum::Tsv       => new CsvEncoder(FormatEnum::Tsv),
            FormatEnum::Xml       => new XmlEncoder(),
            FormatEnum::Toml      => new TomlEncoder(),
            FormatEnum::Base64    => new Base64Encoder(FormatEnum::Base64),
            FormatEnum::Base64Url => new Base64Encoder(FormatEnum::Base64Url),
            FormatEnum::Uri       => new UriEncoder(),
            FormatEnum::Shell     => new ShellEncoder(),
            FormatEnum::Lua       => new LuaEncoder(),
            FormatEnum::Kyaml     => new KyamlEncoder(),
            FormatEnum::Hcl       => new HclEncoder(),
        };
    }
}
