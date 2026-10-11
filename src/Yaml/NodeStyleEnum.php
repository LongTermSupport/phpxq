<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml;

/**
 * How a node was written, or should be written. Collections use Default (block) or Flow; scalars use
 * Default (plain), SingleQuoted, DoubleQuoted, Literal (`|`) or Folded (`>`).
 *
 * @internal
 */
enum NodeStyleEnum
{
    case Default;

    case Flow;

    case SingleQuoted;

    case DoubleQuoted;

    case Literal;

    case Folded;
}
