<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Emitter\YamlEmitterInterface;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * YAML output: delegates to the YAML emitter and prints the `---` separator in front of every result
 * after the first unless the options ask for none.
 */
final readonly class YamlEncoder implements EncoderInterface
{
    public function __construct(private YamlEmitterInterface $emitter = new YamlEmitter())
    {
    }

    public function format(): Format
    {
        return Format::Yaml;
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        $text = $this->emitter->emit($node, new EmitOptions(
            indent: $options->indent,
            colors: $options->colors,
            unwrapScalar: $options->unwrapScalar,
            prettyPrint: $options->prettyPrint,
            noDocSeparator: $options->noDocSeparator,
        ));

        return $resultIndex > 0 && !$options->noDocSeparator ? "---\n" . $text : $text;
    }
}
