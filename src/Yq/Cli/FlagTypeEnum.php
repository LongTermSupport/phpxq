<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Cli;

/**
 * The value kind of a command line flag, as pflag distinguishes them.
 */
enum FlagTypeEnum
{
    case Bool;

    case String;

    case Int;
}
