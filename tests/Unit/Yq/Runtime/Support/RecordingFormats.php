<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Support;

use LTS\PhpXq\Yaml\Node;
use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\FormatEnum;
use LTS\PhpXq\Yq\Format\FormatOptions;
use LTS\PhpXq\Yq\Format\FormatRegistryInterface;

/**
 * A format registry whose codecs describe how they were called: an encoder answers
 * `format|indent|unwrap|result index` and a decoder answers `format|text` as a document.
 */
final readonly class RecordingFormats implements FormatRegistryInterface
{
    public function decoder(FormatEnum $format): DecoderInterface
    {
        return new readonly class($format) implements DecoderInterface {
            public function __construct(private FormatEnum $format)
            {
            }

            public function format(): FormatEnum
            {
                return $this->format;
            }

            public function decode(string $input, FormatOptions $options): iterable
            {
                if ('' === $input) {
                    return;
                }

                yield Node::document(Node::scalar($this->format->value . '|' . $input, '!!str'));
            }
        };
    }

    public function encoder(FormatEnum $format): EncoderInterface
    {
        return new readonly class($format) implements EncoderInterface {
            public function __construct(private FormatEnum $format)
            {
            }

            public function format(): FormatEnum
            {
                return $this->format;
            }

            public function encode(Node $node, FormatOptions $options, int $resultIndex): string
            {
                return \sprintf("%s|%d|%d|%d\n", $this->format->value, $options->indent, $options->unwrapScalar ? 1 : 0, $resultIndex);
            }
        };
    }
}
