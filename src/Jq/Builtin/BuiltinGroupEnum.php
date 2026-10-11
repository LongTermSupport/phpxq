<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

/**
 * The groups the native builtins are registered in, in {@see BuiltinCatalog::GROUPS} order.
 *
 * @internal
 */
enum BuiltinGroupEnum: string
{
    case Type = 'type';

    case Math = 'math';

    case String = 'string';

    case Format = 'format';

    case Collection = 'collection';

    case Control = 'control';

    case Path = 'path';

    case Io = 'io';

    case Regex = 'regex';

    case Date = 'date';
}
