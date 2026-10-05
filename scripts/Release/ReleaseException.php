<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

use RuntimeException;

/**
 * A refusal: the release step cannot continue, and the message says why in terms an owner can act on.
 */
final class ReleaseException extends RuntimeException
{
}
