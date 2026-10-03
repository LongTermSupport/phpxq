<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format;

/**
 * Finds the codec for a format. Implemented by FormatRegistry (format codecs worker), which registers one
 * decoder and one encoder class per format under Yq\Format\Codec.
 *
 * @api
 */
interface FormatRegistryInterface
{
    /**
     * @throws FormatException when the format cannot be read
     */
    public function decoder(Format $format): DecoderInterface;

    /**
     * @throws FormatException when the format cannot be written
     */
    public function encoder(Format $format): EncoderInterface;
}
