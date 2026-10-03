<?php

declare(strict_types=1);

namespace LTS\PhpXq\Json;

use RuntimeException;

/**
 * Invalid JSON text. The message is already phrased the way jq phrases it
 * (for example "Unfinished JSON term at EOF at line 2, column 0").
 *
 * @api
 */
final class JsonSyntaxException extends RuntimeException
{
}
