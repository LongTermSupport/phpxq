<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Support\Jq;

use LTS\PhpXq\Jq\Builtin\BuiltinCatalog;
use LTS\PhpXq\Jq\Builtin\Core\CollectionFunctions;
use LTS\PhpXq\Jq\Builtin\Core\ControlFunctions;
use LTS\PhpXq\Jq\Builtin\Core\FormatFunctions;
use LTS\PhpXq\Jq\Builtin\Core\IoFunctions;
use LTS\PhpXq\Jq\Builtin\Core\MathFunctions;
use LTS\PhpXq\Jq\Builtin\Core\PathFunctions;
use LTS\PhpXq\Jq\Builtin\Core\Prelude;
use LTS\PhpXq\Jq\Builtin\Core\StringFunctions;
use LTS\PhpXq\Jq\Builtin\Core\TypeFunctions;
use LTS\PhpXq\Jq\Builtin\CoreBuiltins;
use LTS\PhpXq\Jq\Builtin\DateBuiltins;
use LTS\PhpXq\Jq\Builtin\RegexBuiltins;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;

/**
 * Registers the builtins into a registry all at once, where production registers them lazily through
 * {@see BuiltinCatalog::registerLazily()}. The builtin tests use it to get a small, fully loaded registry.
 */
final readonly class EagerBuiltins
{
    private function __construct()
    {
    }

    public static function core(DefaultBuiltinRegistry $registry): void
    {
        $recorder = new RecordingRegistry($registry);
        $catalog  = static function () use ($recorder): array {
            $names = array_merge($recorder->signatures(), Prelude::signatures(), CoreBuiltins::OTHER_PROVIDERS);
            $names = array_filter($names, static fn (string $name): bool => !str_starts_with($name, '_'));

            return array_values(array_unique($names));
        };

        TypeFunctions::register($recorder);
        MathFunctions::register($recorder);
        StringFunctions::register($recorder);
        FormatFunctions::register($recorder);
        CollectionFunctions::register($recorder);
        ControlFunctions::register($recorder);
        PathFunctions::register($recorder);
        IoFunctions::register($recorder, $catalog);
        $registry->addPrelude(Prelude::SOURCE);
    }

    public static function regex(DefaultBuiltinRegistry $registry): void
    {
        new RegexBuiltins()->registerNatives($registry);
        $registry->addPrelude(BuiltinCatalog::REGEX_PRELUDE);
    }

    public static function date(DefaultBuiltinRegistry $registry): void
    {
        new DateBuiltins()->registerNatives($registry);
        $registry->addPrelude(BuiltinCatalog::DATE_PRELUDE);
    }
}
