<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
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

    public static function create(): BuiltinRegistryInterface
    {
        $registry = new DefaultBuiltinRegistry();
        BuiltinCatalog::registerLazily($registry);

        return $registry;
    }
}
