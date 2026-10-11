<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Support;

use LTS\PhpXq\Yaml\Emitter\EmitOptions;
use LTS\PhpXq\Yaml\Emitter\YamlEmitter;
use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yaml\Parser\YamlParser;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\FormatOptions;

/**
 * A YAML-only codec standing in for the format registry.
 */
final readonly class YamlCodec implements EncoderInterface, DecoderInterface
{
    public function __construct(private YamlParser $parser, private YamlEmitter $emitter)
    {
    }

    public function encode(Node $node, FormatOptions $options, int $resultIndex): string
    {
        return $this->emitter->emit($node, new EmitOptions(indent: $options->indent, unwrapScalar: $options->unwrapScalar));
    }

    public function decode(string $input, FormatOptions $options): iterable
    {
        return $this->parser->parse($input);
    }
}
