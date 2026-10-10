<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The shells `yq completion` generates a script for, valued with the name typed on the command line.
 *
 * @internal
 */
enum ShellEnum: string
{
    case Bash = 'bash';

    case Zsh = 'zsh';

    case Fish = 'fish';

    case Powershell = 'powershell';
}
