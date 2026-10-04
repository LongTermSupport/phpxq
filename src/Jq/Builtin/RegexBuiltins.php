<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin;

use Closure;
use LTS\PhpXq\Jq\Builtin\Regex\Cartesian;
use LTS\PhpXq\Jq\Builtin\Regex\CodepointCursor;
use LTS\PhpXq\Jq\Builtin\Regex\MatchObjects;
use LTS\PhpXq\Jq\Builtin\Regex\NativeStream;
use LTS\PhpXq\Jq\Builtin\Regex\NativeValue;
use LTS\PhpXq\Jq\Builtin\Regex\OnigRegex;
use LTS\PhpXq\Jq\Builtin\Regex\RegexEngine;
use LTS\PhpXq\Jq\Runtime\Arithmetic;
use LTS\PhpXq\Jq\Runtime\BuiltinProviderInterface;
use LTS\PhpXq\Jq\Runtime\BuiltinRegistryInterface;
use LTS\PhpXq\Jq\Runtime\Eval\ErrorText;
use LTS\PhpXq\Jq\Runtime\FilterInterface;
use LTS\PhpXq\Jq\Runtime\JqException;
use LTS\PhpXq\Json\Values;

/**
 * Regex builtins: match, test, capture, scan, split/2, splits, sub and gsub, on top of the Oniguruma to
 * PCRE translation in src/Jq/Builtin/Regex/ (its limits are documented on RegexTranslator and OnigRegex).
 *
 * Natives: `_match_impl/3` (jq's own helper, which `match` and `capture` are defined on), `test/1,2`,
 * `split/2`, `scan/1,2`, `sub/2,3`, `gsub/2,3`. The rest is jq source in the prelude.
 *
 * @api
 */
final class RegexBuiltins implements BuiltinProviderInterface
{
    public function registerInto(BuiltinRegistryInterface $registry): void
    {
        $this->registerNatives($registry);
        $registry->addPrelude(BuiltinCatalog::REGEX_PRELUDE);
    }

    /**
     * The native half of {@see self::registerInto()}: what {@see BuiltinCatalog} loads on first use.
     */
    public function registerNatives(BuiltinRegistryInterface $registry): void
    {
        $registry->register(new NativeValue(
            '_match_impl',
            3,
            static fn (mixed $input, array $args): mixed => self::matchImpl($input, $args[0], $args[1], Values::isTruthy($args[2])),
        ));
        $registry->register(new NativeValue(
            'test',
            2,
            static fn (mixed $input, array $args): mixed => self::matchImpl($input, $args[0], $args[1], true),
        ));
        $registry->register(new NativeValue(
            'test',
            1,
            static function (mixed $input, array $args): mixed {
                [$pattern, $flags] = self::patternAndFlags($args[0]);

                return self::matchImpl($input, $pattern, $flags, true);
            },
        ));
        $registry->register(new NativeStream(
            'split',
            2,
            static function (mixed $input, array $args, Closure $emit): void {
                Cartesian::each($args, $input, static function (array $values) use ($input, $emit): void {
                    $emit(self::split($input, $values[0], Arithmetic::add('g', $values[1])));
                });
            },
        ));
        $registry->register(new NativeStream(
            'scan',
            2,
            static function (mixed $input, array $args, Closure $emit): void {
                self::scan($input, $args, $emit);
            },
        ));
        $registry->register(new NativeStream(
            'scan',
            1,
            static function (mixed $input, array $args, Closure $emit): void {
                self::scan($input, $args, $emit);
            },
        ));
        foreach ([false, true] as $global) {
            $name = $global ? 'gsub' : 'sub';
            foreach ([2, 3] as $arity) {
                $registry->register(new NativeStream(
                    $name,
                    $arity,
                    static function (mixed $input, array $args, Closure $emit) use ($global): void {
                        self::sub($global, $input, $args, $emit);
                    },
                ));
            }
        }
    }

    /**
     * @return array{string, OnigRegex, bool} the subject, the compiled regex and whether the subject is ASCII
     *
     * @throws JqException
     */
    private static function prepare(mixed $input, mixed $pattern, mixed $flags): array
    {
        if (!\is_string($input)) {
            throw ErrorText::typeError($input, 'cannot be matched, as it is not a string');
        }

        if (!\is_string($pattern)) {
            throw ErrorText::typeError($pattern, 'is not a string');
        }

        if (null !== $flags && !\is_string($flags)) {
            throw ErrorText::typeError($flags, 'is not a string');
        }

        $regex = OnigRegex::compile($pattern, $flags);

        // the subject kind only matters here for the word-escape variant of the pattern
        return [$input, $regex, !$regex->usesWordEscapes || RegexEngine::isAscii($input)];
    }

    /**
     * jq's `test($val)`/`match($val)` convention: a string, or an array of the pattern and optional flags.
     *
     * @return array{mixed, mixed}
     *
     * @throws JqException
     */
    private static function patternAndFlags(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value, null];
        }

        if (\is_array($value) && [] !== $value) {
            return [$value[0], $value[1] ?? null];
        }

        throw new JqException(Values::typeName($value) . ' not a string or array');
    }

    /**
     * @return bool|list<\LTS\PhpXq\Json\JsonObject> a bool in test mode, else the match objects
     *
     * @throws JqException
     */
    private static function matchImpl(mixed $input, mixed $pattern, mixed $flags, bool $test): bool|array
    {
        [$subject, $regex, $ascii] = self::prepare($input, $pattern, $flags);
        if ($test) {
            return RegexEngine::matches($regex, $subject, $ascii);
        }

        $ascii   = RegexEngine::isAscii($subject);
        $cursor  = new CodepointCursor($subject, $ascii);
        $objects = [];
        foreach (RegexEngine::find($regex, $subject, $regex->global, $ascii) as $groups) {
            $objects[] = MatchObjects::match($groups, $regex->groupNames, $cursor);
        }

        return $objects;
    }

    /**
     * @return list<string>
     *
     * @throws JqException
     */
    private static function split(mixed $input, mixed $pattern, mixed $flags): array
    {
        [$subject, $regex, $ascii] = self::prepare($input, $pattern, $flags);
        $parts                     = [];
        $previous                  = 0;
        foreach (RegexEngine::find($regex, $subject, true, $ascii) as $groups) {
            $start    = $groups[0][1];
            $parts[]  = substr($subject, $previous, $start - $previous);
            $previous = $start + \strlen((string)$groups[0][0]);
        }

        $parts[] = substr($subject, $previous);

        return $parts;
    }

    /**
     * @param list<FilterInterface> $args
     * @param Closure(mixed): void  $emit
     *
     * @throws JqException
     */
    private static function scan(mixed $input, array $args, Closure $emit): void
    {
        Cartesian::each($args, $input, static function (array $values) use ($input, $emit): void {
            [$subject, $regex, $ascii] = self::prepare($input, $values[0], Arithmetic::add('g', $values[1] ?? null));
            $groupCount                = \count($regex->groupNames);
            foreach (RegexEngine::find($regex, $subject, true, $ascii) as $groups) {
                if (0 === $groupCount) {
                    $emit((string)$groups[0][0]);

                    continue;
                }

                $strings = [];
                for ($group = 1; $group <= $groupCount; ++$group) {
                    $strings[] = $groups[$group][0] ?? null;
                }

                $emit($strings);
            }
        });
    }

    /**
     * `sub`/`gsub`: the replacement filter runs on the object of named captures for every match. When it
     * yields several values, output number n applies its n-th value to every match, as jq's own
     * definition does; with no match (or no replacement values) the input is the single output.
     *
     * @param list<FilterInterface> $args
     * @param Closure(mixed): void  $emit
     *
     * @throws JqException
     */
    private static function sub(bool $global, mixed $input, array $args, Closure $emit): void
    {
        $replacement = $args[1];
        $parameters  = isset($args[2]) ? [$args[0], $args[2]] : [$args[0]];

        Cartesian::each($parameters, $input, static function (array $values) use ($global, $input, $replacement, $emit): void {
            $flags = $values[1] ?? '';
            if ($global) {
                $flags = Arithmetic::add($flags, 'g');
            }

            [$subject, $regex, $ascii] = self::prepare($input, $values[0], $flags);

            /** @var array<int, mixed> $results */
            $results  = [];
            $count    = 0;
            $previous = 0;
            foreach (RegexEngine::find($regex, $subject, $regex->global, $ascii) as $groups) {
                $start = $groups[0][1];
                $gap   = substr($subject, $previous, $start - $previous);

                /** @var list<mixed> $inserts */
                $inserts = [];
                $replacement->run(MatchObjects::named($groups, $regex->groupNames), static function (mixed $value) use (&$inserts): void {
                    $inserts[] = $value;
                });

                foreach ($inserts as $index => $insert) {
                    $results[$index] = Arithmetic::add($results[$index] ?? null, Arithmetic::add($gap, $insert));
                    $count           = max($count, $index + 1);
                }

                $previous = $start + \strlen((string)$groups[0][0]);
            }

            if (0 === $count) {
                $emit($input);

                return;
            }

            $tail = substr($subject, $previous);
            for ($index = 0; $index < $count; ++$index) {
                $emit(Arithmetic::add($results[$index] ?? null, $tail));
            }
        });
    }
}
