<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The arguments of `--front-matter`: `extract` reads the leading yaml, `process` also appends what follows it.
 */
enum FrontMatterModeEnum: string
{
    case Extract = 'extract';

    case Process = 'process';
}
