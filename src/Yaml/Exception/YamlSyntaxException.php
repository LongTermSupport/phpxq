<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Exception;

use RuntimeException;

/**
 * Raised by the tokenizer and parser for malformed YAML. Line and column are 1-based.
 */
final class YamlSyntaxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $yamlLine,
        public readonly int $yamlColumn,
    ) {
        parent::__construct(\sprintf('yaml: line %d: %s', $yamlLine, $message));
    }
}
