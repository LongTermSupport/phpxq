<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format;

use LogicException;

/**
 * Owned by the format codecs worker (Plan 00004 architecture.md, file ownership map). Skeleton only.
 */
final class FormatRegistry implements FormatRegistryInterface
{
    public function decoder(Format $format): DecoderInterface
    {
        throw new LogicException('FormatRegistry is not implemented for ' . $format->value);
    }

    public function encoder(Format $format): EncoderInterface
    {
        throw new LogicException('FormatRegistry is not implemented for ' . $format->value);
    }
}
