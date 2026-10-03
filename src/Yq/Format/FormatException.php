<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format;

use RuntimeException;

/**
 * Input that is not valid in the named format, or a node tree the output format cannot represent.
 */
final class FormatException extends RuntimeException
{
}
