<?php

declare(strict_types=1);

namespace LTS\PhpXq\Release;

use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;
use LTS\PHPQA\Changelog\ReleaseNotesRenderer;

/**
 * `scripts/release.php`: the decisions of the release flow, over the CHANGELOG.md and VERSION of a project
 * root. Only a result goes to stdout (a version, the notes, a changelog) so a workflow can capture it; what
 * a person reads goes to stderr. Git is not touched: the tags are passed in as a file, one tag per line.
 *
 * Exit codes: 0 done, 1 refused (the reason is on stderr), 2 usage, 3 from `verify` only: the commit carries
 * unreleased entries, so it is not a release commit.
 */
final readonly class ReleaseCommand
{
    public const int EXIT_OK = 0;

    public const int EXIT_REFUSED = 1;

    public const int EXIT_USAGE = 2;

    public const int EXIT_NOT_A_RELEASE = 3;

    public const string CHANGELOG_FILE = 'CHANGELOG.md';

    public const string VERSION_FILE = 'VERSION';

    private const string OPTION_TAGS_FILE = 'tags-file';

    private const string OPTION_DATE = 'date';

    public const string USAGE = <<<'TXT'
        Usage: scripts/release.php <command> [arguments]   (run from the project root)

          next-version [--tags-file=F]            print the version "## Unreleased" releases as; nothing when empty
          prepare [--tags-file=F] [--date=D]      release "## Unreleased" into CHANGELOG.md, write VERSION, print the version
          notes <version>                         print the release notes of a released version
          verify [--tags-file=F]                  exit 0 releasable, 3 not a release commit, 1 refused (VERSION, CHANGELOG.md, tags disagree)
          reconcile <released> <main>             print the released changelog plus the entries main gained since

        --tags-file names a file with one git tag per line (git tag --list); no file means no tags.
        --date is YYYY-MM-DD and defaults to today (UTC).
        TXT;

    public function __construct(
        private string $projectRoot,
        private ReleasePlanner $planner = new ReleasePlanner(),
        private ReleaseVerifier $verifier = new ReleaseVerifier(),
        private ChangelogReconciler $reconciler = new ChangelogReconciler(),
        private ChangelogParser $parser = new ChangelogParser(),
    ) {
    }

    public function run(string ...$arguments): CommandResult
    {
        $arguments = array_values($arguments);
        $name      = array_shift($arguments);
        $command   = null === $name ? null : CommandEnum::tryFrom($name);
        if (!$command instanceof CommandEnum) {
            return new CommandResult(self::EXIT_USAGE, '', self::USAGE . "\n");
        }

        [$positional, $options] = $this->split(...$arguments);

        try {
            return match ($command) {
                CommandEnum::NextVersion => $this->nextVersion($options),
                CommandEnum::Prepare     => $this->prepare($options),
                CommandEnum::Notes       => $this->notes(...$positional),
                CommandEnum::Verify      => $this->verify($options),
                CommandEnum::Reconcile   => $this->reconcile(...$positional),
            };
        } catch (ReleaseException $releaseException) {
            return new CommandResult(self::EXIT_REFUSED, '', $releaseException->getMessage() . "\n");
        }
    }

    /**
     * @param array<string, string> $options
     */
    private function nextVersion(array $options): CommandResult
    {
        $current = $this->currentVersion();
        $next    = $this->planner->nextVersion($this->read(self::CHANGELOG_FILE), $current, $this->released($current, $options));
        if (!$next instanceof SemVer) {
            return new CommandResult(self::EXIT_OK, '', "No release is due: \"## Unreleased\" has no entries.\n");
        }

        return new CommandResult(self::EXIT_OK, $next->toString() . "\n");
    }

    /**
     * @param array<string, string> $options
     */
    private function prepare(array $options): CommandResult
    {
        $current = $this->currentVersion();
        $plan    = $this->planner->plan(
            $this->read(self::CHANGELOG_FILE),
            $current,
            $this->released($current, $options),
            $options[self::OPTION_DATE] ?? gmdate('Y-m-d'),
        );
        if (!$plan instanceof ReleasePlan) {
            return new CommandResult(self::EXIT_OK, '', "No release is due: \"## Unreleased\" has no entries.\n");
        }

        $this->write(self::CHANGELOG_FILE, $plan->changelog);
        $this->write(self::VERSION_FILE, $plan->version->toString() . "\n");

        return new CommandResult(self::EXIT_OK, $plan->version->toString() . "\n", \sprintf("Prepared release %s.\n", $plan->version->toString()));
    }

    private function notes(string ...$positional): CommandResult
    {
        if (1 !== \count($positional)) {
            return new CommandResult(self::EXIT_USAGE, '', self::USAGE . "\n");
        }

        $version = SemVer::parse($positional[0])->toString();
        try {
            $section = $this->parser->release($this->read(self::CHANGELOG_FILE), $version);
        } catch (InvalidChangelogException $invalidChangelogException) {
            throw new ReleaseException($invalidChangelogException->getMessage(), 0, $invalidChangelogException);
        }

        return new CommandResult(self::EXIT_OK, new ReleaseNotesRenderer()->render($section));
    }

    /**
     * @param array<string, string> $options
     */
    private function verify(array $options): CommandResult
    {
        $verdict = $this->verifier->verify($this->read(self::CHANGELOG_FILE), $this->read(self::VERSION_FILE), ...$this->tags($options));

        return match ($verdict) {
            VerdictEnum::Releasable        => new CommandResult(self::EXIT_OK, '', "VERSION, CHANGELOG.md and the tags agree: releasable.\n"),
            VerdictEnum::NotAReleaseCommit => new CommandResult(self::EXIT_NOT_A_RELEASE, '', "\"## Unreleased\" still has entries, so this is not a release commit.\n"),
        };
    }

    private function reconcile(string ...$positional): CommandResult
    {
        if (2 !== \count($positional)) {
            return new CommandResult(self::EXIT_USAGE, '', self::USAGE . "\n");
        }

        return new CommandResult(self::EXIT_OK, $this->reconciler->reconcile($this->readPath($positional[0]), $this->readPath($positional[1])));
    }

    private function currentVersion(): SemVer
    {
        return SemVer::parse(trim($this->read(self::VERSION_FILE)));
    }

    /**
     * @param array<string, string> $options
     */
    private function released(SemVer $version, array $options): bool
    {
        return \in_array('v' . $version->toString(), $this->tags($options), true);
    }

    /**
     * @param array<string, string> $options
     *
     * @return list<string>
     */
    private function tags(array $options): array
    {
        $file = $options[self::OPTION_TAGS_FILE] ?? null;
        if (null === $file) {
            return [];
        }

        $lines = is_file($file) ? file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) : false;
        if (false === $lines) {
            throw new ReleaseException(\sprintf('cannot read the tags file "%s"', $file));
        }

        return array_map(trim(...), $lines);
    }

    /**
     * @return array{list<string>, array<string, string>} positional arguments, then --name=value options
     */
    private function split(string ...$arguments): array
    {
        $positional = [];
        $options    = [];
        foreach ($arguments as $argument) {
            if (1 === preg_match('/^--([a-z-]+)=(.*)$/', $argument, $parts)) {
                $options[$parts[1]] = $parts[2];

                continue;
            }

            $positional[] = $argument;
        }

        return [$positional, $options];
    }

    private function read(string $file): string
    {
        return $this->readPath($this->projectRoot . '/' . $file);
    }

    private function readPath(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (false === $contents) {
            throw new ReleaseException(\sprintf('cannot read %s', $path));
        }

        return $contents;
    }

    private function write(string $file, string $contents): void
    {
        $path = $this->projectRoot . '/' . $file;
        if (false === file_put_contents($path, $contents)) {
            throw new ReleaseException(\sprintf('cannot write %s', $path));
        }
    }
}
