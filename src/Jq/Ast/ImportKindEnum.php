<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Ast;

/**
 * @internal
 */
enum ImportKindEnum: string
{
    /** `import "a" as name;` namespaced definitions */
    case Import = 'import';

    /** `include "a";` definitions spliced in unprefixed */
    case Include = 'include';

    /** `import "a" as $name;` the JSON values of a data file, bound as an array */
    case Data = 'data';
}
