<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yaml;

/**
 * The structural kind of a {@see Node}; mirrors the node kinds of the reference yq (go-yaml v3).
 */
enum NodeKind
{
    /** One YAML document; `content` holds exactly one root node. */
    case Document;

    /** A block or flow sequence; `content` holds the items. */
    case Sequence;

    /** A block or flow mapping; `content` is the flat list key0, value0, key1, value1, ... */
    case Mapping;

    /** A plain, quoted or block scalar; `value` holds the decoded text. */
    case Scalar;

    /** An alias (`*name`); `aliasTarget` points at the anchored node. */
    case Alias;
}
