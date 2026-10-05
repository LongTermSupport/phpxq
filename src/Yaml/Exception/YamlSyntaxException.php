<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Exception;

use RuntimeException;

/**
 * Raised by the tokenizer and parser for malformed YAML. The line is 1-based and part of the message.
 */
final class YamlSyntaxException extends RuntimeException
{
    public function __construct(string $message, int $line)
    {
        parent::__construct(\sprintf('yaml: line %d: %s', $line, $message));
    }
}
