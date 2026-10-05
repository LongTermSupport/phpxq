<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Format\Codec;

/**
 * Where an {@see XmlElement} is while the reader builds it: nothing but comments seen, text seen, or a child
 * just closed.
 */
enum ElementStateEnum
{
    case Started;

    case Chardata;

    case Ended;
}
