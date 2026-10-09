<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

use LTS\PhpXq\Jq\Builtin\Core\CollectionFunctions;
use LTS\PhpXq\Jq\Builtin\Core\ControlFunctions;
use LTS\PhpXq\Jq\Builtin\Core\FormatFunctions;
use LTS\PhpXq\Jq\Builtin\Core\IoFunctions;
use LTS\PhpXq\Jq\Builtin\Core\MathFunctions;
use LTS\PhpXq\Jq\Builtin\Core\PathFunctions;
use LTS\PhpXq\Jq\Builtin\Core\Prelude;
use LTS\PhpXq\Jq\Builtin\Core\RecordingRegistry;
use LTS\PhpXq\Jq\Builtin\Core\StringFunctions;
use LTS\PhpXq\Jq\Builtin\Core\TypeFunctions;
use LTS\PhpXq\Jq\Runtime\BuiltinProviderInterface;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;

/**
 * Everything that is not regex or date/time: type, length, keys, paths, math, strings, formats, SQL-style,
 * streaming, input/output, env, and the jq-defined prelude. The natives live in the classes of
 * {@see Core}, the jq source in {@see Prelude}.
 *
 * @internal
 */
final readonly class CoreBuiltins implements BuiltinProviderInterface
{
    /**
     * Builtins the regex and date providers register, so that `builtins` lists them too.
     */
    public const array OTHER_PROVIDERS = [
        'test/1', 'test/2', 'match/1', 'match/2', 'capture/1', 'capture/2', 'scan/1', 'scan/2', 'split/2',
        'splits/1', 'splits/2', 'sub/2', 'sub/3', 'gsub/2', 'gsub/3',
        'mktime/0', 'gmtime/0', 'localtime/0', 'strftime/1', 'strflocaltime/1', 'strptime/1', 'now/0',
        'todate/0', 'fromdate/0', 'date/0', 'dateadd/2', 'datesub/2', 'fromdateiso8601/0', 'todateiso8601/0',
    ];

    public function registerInto(BuiltinRegistryInterface $registry): void
    {
        $recorder = new RecordingRegistry($registry);
        $catalog  = static function () use ($recorder): array {
            $names = array_merge($recorder->signatures(), Prelude::signatures(), self::OTHER_PROVIDERS);
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
}
