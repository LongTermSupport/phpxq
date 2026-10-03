<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * How the header of a YAML input (see {@see HeaderSplitter}) is handled.
 *
 * `eval` slurps every file's header and prints it with that file's first document; `eval-all` slurps only
 * the first file's header (later files keep their comments on their nodes so merging them works) and
 * prints it with the first document.
 */
enum HeaderModeEnum
{
    case None;

    case PerFile;

    case FirstFile;
}
