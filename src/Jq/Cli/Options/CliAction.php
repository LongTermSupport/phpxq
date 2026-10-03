<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli\Options;

/**
 * What the command line asks jq to do.
 *
 * @api
 */
enum CliAction
{
    /** compile the program and run it over the inputs */
    case Run;

    /** `-h`: print the full usage text on stdout and exit 0 */
    case Help;

    /** `-V`: print the version on stdout and exit 0 */
    case Version;

    /** `--build-configuration` */
    case BuildConfiguration;
}
