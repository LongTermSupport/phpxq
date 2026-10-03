<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Exception\YamlSyntaxException;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yaml\Parser\YamlParserInterface;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatException;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * YAML input: delegates to the YAML parser and reports its syntax errors as format errors.
 */
final readonly class YamlDecoder implements DecoderInterface
{
    public function __construct(private YamlParserInterface $parser = new YamlParser())
    {
    }

    public function format(): Format
    {
        return Format::Yaml;
    }

    public function decode(string $input, FormatOptions $options): iterable
    {
        try {
            yield from $this->parser->parse($input);
        } catch (YamlSyntaxException $yamlSyntaxException) {
            throw new FormatException($yamlSyntaxException->getMessage(), 0, $yamlSyntaxException);
        }
    }
}
