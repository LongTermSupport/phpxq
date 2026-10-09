<?php

declare(strict_types=1);

namespace LTS\PhpXq\Qa;

/**
 * Decides which source files a change needs mutated, so a pull request runs Infection on the code it could have
 * weakened the checking of, not on all of src/ (hours) and not on nothing.
 *
 * - A changed source file (added, modified, renamed or copied) is mutated. A deleted one cannot be.
 * - A changed, renamed or deleted unit test maps to the source it mirrors: tests/Unit/Foo/BarTest.php to
 *   src/Foo/Bar.php, else every file of the directory src/Foo/Bar/, else every file of the nearest directory up the
 *   mirrored path that src/ has. Fixtures under tests/Fixtures/<Dir>/ map the same way. Reaching src/ itself, a
 *   test of a top-level directory src/ lacks, or an unrecognised test path mutates everything.
 * - Shared test infrastructure (tests/Support, tests/bootstrap.php) and the build and QA configuration that decide
 *   how tests run or what the floors are (composer.json, composer.lock, qaConfig/phpunit.xml, qaConfig/qa.php)
 *   mutate everything.
 * - Tests of code outside src/ (qaConfig rules, release and QA tooling, test support) and the conformance suites,
 *   which record no coverage and so cannot kill a mutant, need no mutation. So does any other path.
 */
final readonly class MutationScope
{
    private const string SOURCE_ROOT = 'src';

    private const string UNIT_ROOT = 'tests/Unit/';

    private const string FIXTURE_ROOT = 'tests/Fixtures/';

    private const string TEST_SUFFIX = 'Test.php';

    private const string QUOTE = '"';

    private const array EVERYTHING = [
        'composer.json',
        'composer.lock',
        'qaConfig/phpunit.xml',
        'qaConfig/qa.php',
        'tests/bootstrap.php',
    ];

    private const array EVERYTHING_UNDER = ['tests/Support/'];

    private const array NOTHING_UNDER = [
        'tests/Unit/QaConfig/',
        'tests/Unit/Release/',
        'tests/Unit/Support/',
        'tests/Unit/Qa/',
        'tests/Conformance/',
        'tests/Fixtures/Defence/',
    ];

    /** @var list<string> */
    private array $sources;

    /**
     * @param list<string> $sources every PHP file under src/, project-relative
     */
    public function __construct(array $sources)
    {
        sort($sources);
        $this->sources = $sources;
    }

    /**
     * Resolves `git diff -z --name-status -M` output; output that cannot be parsed mutates everything.
     */
    public function resolveNameStatusZ(string $output): ScopeResult
    {
        $changes = FileChange::parseNameStatusZ($output);

        return null === $changes ? $this->everything() : $this->resolve(...$changes);
    }

    public function resolve(FileChange ...$changes): ScopeResult
    {
        $files = [];
        foreach ($changes as $change) {
            $mapped = $this->map($change);
            if (null === $mapped) {
                return $this->everything();
            }

            array_push($files, ...$mapped);
        }

        $files = array_values(array_unique($files));
        sort($files);

        return new ScopeResult([] === $files ? ScopeKindEnum::None : ScopeKindEnum::Files, $files, $this->sources);
    }

    private function everything(): ScopeResult
    {
        return new ScopeResult(ScopeKindEnum::All, $this->sources, $this->sources);
    }

    /**
     * @return ?list<string> the source files the change maps to; null for everything
     */
    private function map(FileChange $change): ?array
    {
        $path = $change->path;
        // git C-quotes a path with unusual bytes unless asked for -z output; such a path cannot be placed, so it
        // must count as touching everything rather than nothing.
        if (str_starts_with($path, self::QUOTE)) {
            return null;
        }

        if (\in_array($path, self::EVERYTHING, true) || self::startsWithAny($path, ...self::EVERYTHING_UNDER)) {
            return null;
        }

        if (self::startsWithAny($path, ...self::NOTHING_UNDER)) {
            return [];
        }

        if (str_starts_with($path, self::SOURCE_ROOT . '/')) {
            return FileChange::DELETED !== $change->status && \in_array($path, $this->sources, true) ? [$path] : [];
        }

        if (str_starts_with($path, self::UNIT_ROOT)) {
            return $this->mirror(substr($path, \strlen(self::UNIT_ROOT)), true);
        }

        if (str_starts_with($path, self::FIXTURE_ROOT)) {
            return $this->mirror(substr($path, \strlen(self::FIXTURE_ROOT)), false);
        }

        return str_starts_with($path, 'tests/') ? null : [];
    }

    /**
     * @return ?list<string>
     */
    private function mirror(string $relative, bool $isUnitTest): ?array
    {
        $directory = \dirname($relative);
        $directory = '.' === $directory ? '' : $directory;
        $base      = self::SOURCE_ROOT . ('' === $directory ? '' : '/' . $directory);

        $name = basename($relative);
        if ($isUnitTest && str_ends_with($name, self::TEST_SUFFIX)) {
            $subject = $base . '/' . substr($name, 0, -\strlen(self::TEST_SUFFIX));
            if (\in_array($subject . '.php', $this->sources, true)) {
                return [$subject . '.php'];
            }

            $inDirectory = $this->under($subject);
            if ([] !== $inDirectory) {
                return $inDirectory;
            }
        }

        while ($base !== self::SOURCE_ROOT) {
            $inDirectory = $this->under($base);
            if ([] !== $inDirectory) {
                return $inDirectory;
            }

            $base = \dirname($base);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function under(string $directory): array
    {
        $prefix = $directory . '/';

        return array_values(array_filter($this->sources, static fn (string $source): bool => str_starts_with($source, $prefix)));
    }

    private static function startsWithAny(string $path, string ...$prefixes): bool
    {
        return array_any($prefixes, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }
}
