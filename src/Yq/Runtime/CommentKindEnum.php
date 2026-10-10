<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime;

/**
 * Which of a node's three comments an operator reads or writes. {@see self::All} is for writing only: it
 * stands for the head, line and foot comment together.
 *
 * @internal
 */
enum CommentKindEnum: string
{
    case Head = 'head';

    case Line = 'line';

    case Foot = 'foot';

    case All = 'all';
}
