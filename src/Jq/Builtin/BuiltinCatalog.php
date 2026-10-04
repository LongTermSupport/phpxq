<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

use Closure;
use LTS\PhpXq\Jq\Builtin\Core\CollectionFunctions;
use LTS\PhpXq\Jq\Builtin\Core\ControlFunctions;
use LTS\PhpXq\Jq\Builtin\Core\FormatFunctions;
use LTS\PhpXq\Jq\Builtin\Core\IoFunctions;
use LTS\PhpXq\Jq\Builtin\Core\MathFunctions;
use LTS\PhpXq\Jq\Builtin\Core\PathFunctions;
use LTS\PhpXq\Jq\Builtin\Core\Prelude;
use LTS\PhpXq\Jq\Builtin\Core\StringFunctions;
use LTS\PhpXq\Jq\Builtin\Core\TypeFunctions;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\DefaultBuiltinRegistry;

/**
 * Which builtin group registers which `name/arity`, so that a registry can load a group's class the first time
 * one of its names is looked up instead of at start-up. A typical program touches two or three of the ten
 * groups and each unused one costs a file compile (about 3500 lines in all), the largest part of the start-up
 * time of a short run (see CLAUDE/Plan/Completed/00007-performance-optimisation-round/results.md). The prelude texts of the
 * regex and date groups live here for the same reason: reading them must not load the group classes.
 *
 * {@see self::GROUPS} is the registration order of {@see CoreBuiltins}, {@see RegexBuiltins} and
 * {@see DateBuiltins}; CORE_GROUPS are those of {@see CoreBuiltins}, the others belong to the regex and date
 * providers. BuiltinCatalogTest fails when it drifts from what the groups really register.
 *
 * @internal
 */
final readonly class BuiltinCatalog
{
    public const array GROUPS = [
        BuiltinGroupEnum::Type->value       => ['type/0', 'not/0', 'length/0', 'utf8bytelength/0', 'keys/0', 'keys_unsorted/0', 'has/1', 'contains/1', 'tojson/0', 'tostring/0', 'fromjson/0', 'tonumber/0', 'toboolean/0', 'toarray/0', 'abs/0', 'ascii/0', 'infinite/0', 'nan/0', 'isinfinite/0', 'isnan/0', 'isnormal/0', 'have_literal_numbers/0', 'have_decnum/0'],
        BuiltinGroupEnum::Math->value       => ['acos/0', 'acosh/0', 'asin/0', 'asinh/0', 'atan/0', 'atanh/0', 'cbrt/0', 'ceil/0', 'cos/0', 'cosh/0', 'erf/0', 'erfc/0', 'exp/0', 'exp10/0', 'exp2/0', 'expm1/0', 'fabs/0', 'floor/0', 'gamma/0', 'j0/0', 'j1/0', 'lgamma/0', 'log/0', 'log10/0', 'log1p/0', 'log2/0', 'logb/0', 'nearbyint/0', 'pow10/0', 'rint/0', 'round/0', 'significand/0', 'sin/0', 'sinh/0', 'sqrt/0', 'tan/0', 'tanh/0', 'tgamma/0', 'trunc/0', 'y0/0', 'y1/0', 'atan2/2', 'copysign/2', 'drem/2', 'fdim/2', 'fmax/2', 'fmin/2', 'fmod/2', 'hypot/2', 'nextafter/2', 'nexttoward/2', 'pow/2', 'remainder/2', 'scalb/2', 'ldexp/2', 'scalbln/2', 'fma/3', 'jn/2', 'yn/2', 'frexp/0', 'modf/0', 'lgamma_r/0'],
        BuiltinGroupEnum::String->value     => ['startswith/1', 'endswith/1', 'ltrimstr/1', 'rtrimstr/1', 'trim/0', 'ltrim/0', 'rtrim/0', 'ascii_downcase/0', 'ascii_upcase/0', 'explode/0', 'implode/0', 'split/1', 'join/1', '_strindices/1', '_array_indices/1'],
        BuiltinGroupEnum::Format->value     => ['format/1'],
        BuiltinGroupEnum::Collection->value => ['sort/0', 'unique/0', 'min/0', 'max/0', '_sort_by_impl/1', '_group_by_impl/1', '_unique_by_impl/1', '_min_by_impl/1', '_max_by_impl/1', 'reverse/0', 'flatten/0', 'flatten/1', 'add/0', 'transpose/0', 'bsearch/1', 'to_entries/0', 'from_entries/0'],
        BuiltinGroupEnum::Control->value    => ['empty/0', 'error/0', 'error/1', 'select/1', 'map/1', 'first/1', 'limit/2', 'skip/2', 'last/1', 'isempty/1', 'any/2', 'all/2', 'range/1', 'range/2', 'range/3', 'recurse/0', 'recurse/1', 'recurse/2', 'repeat/1'],
        BuiltinGroupEnum::Path->value       => ['path/1', 'getpath/1', 'setpath/2', 'delpaths/1', 'paths/0', 'tostream/0', 'fromstream/1'],
        BuiltinGroupEnum::Io->value         => ['input/0', 'inputs/0', 'debug/0', 'debug/1', 'stderr/0', 'input_filename/0', 'input_line_number/0', 'halt/0', 'halt_error/1', 'env/0', 'get_search_list/0', 'builtins/0'],
        BuiltinGroupEnum::Regex->value      => ['_match_impl/3', 'test/2', 'test/1', 'split/2', 'scan/2', 'scan/1', 'sub/2', 'sub/3', 'gsub/2', 'gsub/3'],
        BuiltinGroupEnum::Date->value       => ['now/0', 'mktime/0', 'gmtime/0', 'localtime/0', 'strftime/1', 'strflocaltime/1', 'strptime/1'],
    ];

    public const string REGEX_PRELUDE = <<<'JQ'
        def match(re; mode): _match_impl(re; mode; false) | .[];
        def match($val): ($val | type) as $vt
          | if $vt == "string" then match($val; null)
            elif $vt == "array" and ($val | length) > 1 then match($val[0]; $val[1])
            elif $vt == "array" and ($val | length) > 0 then match($val[0]; null)
            else error($vt + " not a string or array") end;
        def capture(re; mods): match(re; mods)
          | [.captures | .[] | select(.name != null) | {key: .name, value: .string}] | from_entries;
        def capture($val): ($val | type) as $vt
          | if $vt == "string" then capture($val; null)
            elif $vt == "array" and ($val | length) > 1 then capture($val[0]; $val[1])
            elif $vt == "array" and ($val | length) > 0 then capture($val[0]; null)
            else error($vt + " not a string or array") end;
        def splits($re; flags): split($re; flags) | .[];
        def splits($re): splits($re; null);
        JQ;

    public const string DATE_PRELUDE = <<<'JQ'
        def todate: strftime("%Y-%m-%dT%H:%M:%SZ");
        def fromdateiso8601: strptime("%Y-%m-%dT%H:%M:%SZ") | mktime;
        def todateiso8601: strftime("%Y-%m-%dT%H:%M:%SZ");
        def fromdate: fromdateiso8601;
        def date: todate;
        def dateadd(u; n): . + n;
        def datesub(u; n): . - n;
        JQ;

    private const array CORE_GROUPS = [
        BuiltinGroupEnum::Type,
        BuiltinGroupEnum::Math,
        BuiltinGroupEnum::String,
        BuiltinGroupEnum::Format,
        BuiltinGroupEnum::Collection,
        BuiltinGroupEnum::Control,
        BuiltinGroupEnum::Path,
        BuiltinGroupEnum::Io,
    ];

    private function __construct()
    {
    }

    /**
     * Every group lazily, then the preludes of core, regex and date in that order.
     */
    public static function registerLazily(DefaultBuiltinRegistry $registry): void
    {
        foreach (self::GROUPS as $group => $signatures) {
            $registry->registerLazy(self::loader($group), ...$signatures);
        }

        $registry->addPrelude(Prelude::SOURCE);
        $registry->addPrelude(self::REGEX_PRELUDE);
        $registry->addPrelude(self::DATE_PRELUDE);
    }

    /**
     * What `builtins` reports: the natives of the core groups, the prelude and the regex and date names,
     * without the internal ones (leading underscore).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $core = [];
        foreach (self::CORE_GROUPS as $group) {
            $core = [...$core, ...self::GROUPS[$group->value]];
        }

        $names = array_merge($core, Prelude::signatures(), CoreBuiltins::OTHER_PROVIDERS);
        $names = array_filter($names, static fn (string $name): bool => !str_starts_with($name, '_'));

        return array_values(array_unique($names));
    }

    /**
     * @return Closure(BuiltinRegistryInterface): void registers the natives of one group
     */
    public static function loader(string $group): Closure
    {
        return static function (BuiltinRegistryInterface $registry) use ($group): void {
            match (BuiltinGroupEnum::tryFrom($group)) {
                BuiltinGroupEnum::Type       => TypeFunctions::register($registry),
                BuiltinGroupEnum::Math       => MathFunctions::register($registry),
                BuiltinGroupEnum::String     => StringFunctions::register($registry),
                BuiltinGroupEnum::Format     => FormatFunctions::register($registry),
                BuiltinGroupEnum::Collection => CollectionFunctions::register($registry),
                BuiltinGroupEnum::Control    => ControlFunctions::register($registry),
                BuiltinGroupEnum::Path       => PathFunctions::register($registry),
                BuiltinGroupEnum::Io         => IoFunctions::register($registry, self::names(...)),
                BuiltinGroupEnum::Regex      => new RegexBuiltins()->registerNatives($registry),
                default      => new DateBuiltins()->registerNatives($registry),
            };
        };
    }
}
