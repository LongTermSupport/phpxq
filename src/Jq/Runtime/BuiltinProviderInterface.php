<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * A group of builtins that knows how to register itself. One provider per owner keeps parallel work in
 * separate files: core, regex and date each implement this.
 *
 * @internal
 */
interface BuiltinProviderInterface
{
    /**
     * Register natives with {@see BuiltinRegistryInterface::register()} and jq-defined builtins with
     * {@see BuiltinRegistryInterface::addPrelude()}.
     */
    public function registerInto(BuiltinRegistryInterface $registry): void;
}
