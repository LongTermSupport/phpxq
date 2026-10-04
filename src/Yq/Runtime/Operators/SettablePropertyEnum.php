<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

/**
 * The node properties `X <name> = "..."` assigns instead of a value. The comment spellings (`head_comment`,
 * `headComment`) both set {@see self::Head}; `comments` sets all three.
 */
enum SettablePropertyEnum: string
{
    case Style = 'style';

    case Tag = 'tag';

    case Anchor = 'anchor';

    case Alias = 'alias';

    case Comments = 'comments';

    case Head = 'head';

    case Line = 'line';

    case Foot = 'foot';
}
