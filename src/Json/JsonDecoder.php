<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use Generator;
use LogicException;

/**
 * JSON decoder. OWNER: JSON codec worker. Skeleton only.
 *
 * @api
 */
final class JsonDecoder implements JsonDecoderInterface
{
    public function decodeOne(string $text): mixed
    {
        throw new LogicException('JsonDecoder::decodeOne is not implemented');
    }

    public function decodeAll(string $text, bool $seq = false): Generator
    {
        throw new LogicException('JsonDecoder::decodeAll is not implemented');
    }
}
