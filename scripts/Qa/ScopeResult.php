<?php

declare(strict_types=1);

namespace LTS\PhpXq\Qa;

/**
 * The source files a change needs mutated, and the Infection excludes that confine a run to them.
 */
final readonly class ScopeResult
{
    private const string SOURCE_PREFIX = 'src/';

    /**
     * @param list<string> $files      in-scope source files, project-relative, sorted
     * @param list<string> $allSources every source file, project-relative, sorted
     */
    public function __construct(
        public ScopeKindEnum $kind,
        public array $files,
        private array $allSources,
    ) {
    }

    /**
     * The source files outside the scope, relative to src/, for Infection's `source.excludes`.
     *
     * Infection matches an exclude as a substring of a file's path relative to src/, so an exclude that is also a
     * substring of an in-scope path would drop that file too. Such an exclude is left out: the outside file is
     * mutated as well, rather than an in-scope file silently not at all.
     *
     * @return list<string>
     */
    public function excludes(): array
    {
        $inScope = array_map(self::relative(...), $this->files);
        $excludes = [];
        foreach (array_diff($this->allSources, $this->files) as $outside) {
            $relative = self::relative($outside);
            if (array_any($inScope, static fn (string $kept): bool => str_contains($kept, $relative))) {
                continue;
            }

            $excludes[] = $relative;
        }

        return $excludes;
    }

    private static function relative(string $path): string
    {
        return substr($path, \strlen(self::SOURCE_PREFIX));
    }
}
