<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

use LTS\PhpXq\Jq\Runtime\BuiltinRegistry;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;

/**
 * Builds the registry holding every builtin. FROZEN: the provider list below is the integration point,
 * each provider is edited only by its owner.
 *
 * @api
 */
final class StandardBuiltins
{
    private function __construct()
    {
    }

    public static function create(): BuiltinRegistry
    {
        $registry = new DefaultBuiltinRegistry();
        foreach ([new CoreBuiltins(), new RegexBuiltins(), new DateBuiltins()] as $provider) {
            $provider->registerInto($registry);
        }

        return $registry;
    }
}
