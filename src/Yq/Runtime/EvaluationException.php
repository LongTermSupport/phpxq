<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

use RuntimeException;

/**
 * A runtime error in an expression (wrong operand type, unknown function, failed cast...). The CLI
 * prints `Error: <message>` to stderr and exits 1, as the reference does.
 *
 * @api
 */
final class EvaluationException extends RuntimeException
{
}
