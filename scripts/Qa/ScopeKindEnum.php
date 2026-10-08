<?php

declare(strict_types=1);

namespace LTS\PhpXq\Qa;

/**
 * How much of src/ a change needs mutated.
 */
enum ScopeKindEnum: string
{
    /** The change cannot weaken what the unit tests check in src/: no mutation run. */
    case None = 'none';

    /** Only the listed source files. */
    case Files = 'files';

    /** Every source file: the full mutation run. */
    case All = 'all';
}
