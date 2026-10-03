<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml\Parser;

use LogicException;

/**
 * Owned by the YAML parser worker (Plan 00004 architecture.md, file ownership map). Skeleton only.
 */
final class YamlParser implements YamlParserInterface
{
    public function parse(string $yaml): iterable
    {
        throw new LogicException('YamlParser is not implemented');
    }
}
