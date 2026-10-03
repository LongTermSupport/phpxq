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
 * YAML output: delegates to the YAML emitter and prints the `---` separator in front of a result whose
 * `$resultIndex` is above zero, unless the options ask for none.
 *
 * Pass the position of the result's output document, not of the result: the reference prints `---` only
 * where the document changes, so every result taken from the first document is index 0 (`.[]` prints
 * `a` and `b` with no separator) and results from the next document are index 1.
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
