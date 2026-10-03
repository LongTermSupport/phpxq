<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Runtime;

/**
 * A group of builtins that knows how to register itself. One provider per owner keeps parallel work in
 * separate files: core, regex and date each implement this.
 *
 * @api
 */
interface BuiltinProvider
{
    /**
     * Register natives with {@see BuiltinRegistry::register()} and jq-defined builtins with
     * {@see BuiltinRegistry::addPrelude()}.
     */
    public function registerInto(BuiltinRegistry $registry): void;
}
