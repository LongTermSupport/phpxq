<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Qa;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * Drives the real scripts/mutation-scope.bash and scripts/check-qa-measurements.bash in a throwaway git repository
 * whose one commit since the base changes src/A.php, so the scope is `files` (A.php in, B.php excluded).
 *
 * @internal
 */
#[CoversNothing]
final class MutationScopeScriptsTest extends TestCase
{
    private const array SCRIPTS = ['check-qa-measurements.bash', 'mutation-scope.bash', 'mutation-scope.php'];

    private const string BASE = 'HEAD~1';

    private const string OVERRIDE = 'qaConfig/infection.json';

    private string $root;

    protected function setUp(): void
    {
        $project    = \dirname(__DIR__, 3);
        $this->root = sys_get_temp_dir() . '/phpxq-mutation-scope-' . bin2hex(random_bytes(6));
        foreach (['scripts', 'src', 'qaConfig', 'var/qa/phpunit_logs', 'var/qa/infection'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o755, true);
        }

        foreach (self::SCRIPTS as $script) {
            copy($project . '/scripts/' . $script, $this->root . '/scripts/' . $script);
            chmod($this->root . '/scripts/' . $script, 0o755);
        }

        symlink($project . '/vendor', $this->root . '/vendor');
        file_put_contents($this->root . '/.gitignore', "/vendor\n/var/\n/qaConfig/infection.json\n");
        file_put_contents($this->root . '/src/A.php', "<?php\n");
        file_put_contents($this->root . '/src/B.php', "<?php\n");
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'base');
        file_put_contents($this->root . '/src/A.php', "<?php\n\n// changed\n");
        $this->git('commit', '-q', '-am', 'change');

        file_put_contents(
            $this->root . '/var/qa/phpunit_logs/coverage.clover',
            '<metrics files="2" statements="100" coveredstatements="95" methods="10" coveredmethods="9"/>' . "\n",
        );
        touch($this->root . '/var/qa/phpunit_logs/coverage.clover', time() - 60);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->root);
    }

    public function testAZeroMutantScopedRunWithoutTheScopedOverrideFails(): void
    {
        $this->summary(0);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString(self::OVERRIDE, $check->getOutput());
    }

    public function testAZeroMutantRunOverTheOverrideWrittenForTheScopePasses(): void
    {
        $write = $this->scope('--write');
        self::assertSame(0, $write->getExitCode(), $write->getErrorOutput());
        $this->summary(0);

        $check = $this->check();

        self::assertSame(0, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('no mutants were generated', $check->getOutput());
    }

    public function testAnOverrideThatDoesNotMatchTheScopeFails(): void
    {
        self::assertSame(0, $this->scope('--write')->getExitCode());
        $override = $this->read(self::OVERRIDE);
        file_put_contents($this->root . '/' . self::OVERRIDE, str_replace('"B.php"', '"Other.php"', $override));
        $this->summary(0);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString(self::OVERRIDE, $check->getOutput());
    }

    public function testAnOverrideLeftBehindWhenEverythingIsInScopeFails(): void
    {
        self::assertSame(0, $this->scope('--write')->getExitCode());
        file_put_contents($this->root . '/composer.json', "{}\n");
        $this->git('add', 'composer.json');
        $this->git('commit', '-q', '-m', 'everything');
        $this->summary(3);

        $check = $this->check('HEAD~2');

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString(self::OVERRIDE, $check->getOutput());
    }

    public function testAnOverrideThatCannotBeWrittenFailsTheScopeStep(): void
    {
        // A file where qaConfig/ should be: the write fails even for root, whom a read-only directory does not stop.
        rmdir($this->root . '/qaConfig');
        file_put_contents($this->root . '/qaConfig', '');

        $write = $this->scope('--write');

        self::assertSame(1, $write->getExitCode(), $write->getOutput());
        self::assertStringNotContainsString('wrote', $write->getOutput());
    }

    public function testABaseGitCannotDiffAgainstLeavesScopeAllForTheWorkflow(): void
    {
        $githubOutput = $this->root . '/var/github-output';

        $scope = $this->execute(['scripts/mutation-scope.bash', 'no-such-ref', '--write'], ['GITHUB_OUTPUT' => $githubOutput]);

        self::assertNotSame(0, $scope->getExitCode());
        self::assertSame("scope=all\n", $this->read('var/github-output'));
    }

    private function scope(string ...$arguments): Process
    {
        return $this->execute(['scripts/mutation-scope.bash', self::BASE, ...$arguments]);
    }

    private function check(string $base = self::BASE): Process
    {
        return $this->execute(['scripts/check-qa-measurements.bash'], ['PHPXQ_MUTATION_BASE' => $base]);
    }

    private function summary(int $total): void
    {
        file_put_contents(
            $this->root . '/var/qa/infection/summary-log.txt',
            \sprintf("Total: %d\nKilled by Test Framework: %d\nSkipped: 0\nIgnored: 0\nNot Covered: 0\n", $total, $total),
        );
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->root . '/' . $relative);
        if (false === $contents) {
            throw new RuntimeException('Could not read ' . $relative);
        }

        return $contents;
    }

    private function git(string ...$arguments): void
    {
        $process = $this->execute(['git', '-c', 'user.name=test', '-c', 'user.email=test@example.invalid', ...$arguments]);
        if (0 !== $process->getExitCode()) {
            throw new RuntimeException('git failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * @param array<int|string, string> $command
     * @param array<string, string>     $environment
     */
    private function execute(array $command, array $environment = []): Process
    {
        $process = new Process(array_values($command), $this->root, ['GITHUB_OUTPUT' => '', 'PHPXQ_MUTATION_BASE' => '', ...$environment]);
        $process->run();

        return $process;
    }
}
