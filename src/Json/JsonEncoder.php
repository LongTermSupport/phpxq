<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use LogicException;

/**
 * JSON encoder. OWNER: JSON codec worker. Skeleton only.
 *
 * @api
 */
final class JsonEncoder implements JsonEncoderInterface
{
    public function encode(mixed $value, EncodeOptions $options): string
    {
        throw new LogicException('JsonEncoder::encode is not implemented');
    }
}
