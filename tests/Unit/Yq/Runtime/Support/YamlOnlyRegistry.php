<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Yq\Runtime\Support;

use LTS\PhpXq\Yq\Format\DecoderInterface;
use LTS\PhpXq\Yq\Format\EncoderInterface;
use LTS\PhpXq\Yq\Format\Format;
use LTS\PhpXq\Yq\Format\FormatRegistryInterface;

/**
 * Serves the YAML codec for every format.
 */
final readonly class YamlOnlyRegistry implements FormatRegistryInterface
{
    public function __construct(private YamlCodec $codec)
    {
    }

    public function decoder(Format $format): DecoderInterface
    {
        return $this->codec;
    }

    public function encoder(Format $format): EncoderInterface
    {
        return $this->codec;
    }
}
