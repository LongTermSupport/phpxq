<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Builtin\Regex;

/**
 * The result of translating an Oniguruma pattern: PCRE source without delimiters, the name of every
 * capturing group in order (null for an unnamed group), and whether the pattern uses `\w \W \b \B`,
 * which differ between Oniguruma and PCRE for non-ASCII text.
 *
 * @internal
 */
final readonly class TranslatedPattern
{
    /**
     * @param list<?string> $groupNames
     */
    public function __construct(
        public string $pcre,
        public array $groupNames,
        public bool $usesWordEscapes,
    ) {
    }
}
