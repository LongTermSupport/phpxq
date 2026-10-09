<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

/**
 * Which part of a SemVer version a release moves, weakest first so that the strongest wins.
 */
enum BumpEnum: int
{
    public function strongest(self $other): self
    {
        return $other->value > $this->value ? $other : $this;
    }

    case Patch = 1;
    case Minor = 2;
    case Major = 3;
}
