<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Token;

use LogicException;

/**
 * Owned by the YAML parser worker (Plan 00004 architecture.md, file ownership map). Skeleton only.
 */
final class YamlTokenizer implements YamlTokenizerInterface
{
    public function tokenize(string $yaml): iterable
    {
        throw new LogicException('YamlTokenizer is not implemented');
    }
}
