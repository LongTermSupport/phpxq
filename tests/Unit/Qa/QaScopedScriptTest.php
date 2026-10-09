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
 * Drives the real scripts/qa-scoped.bash in a throwaway git repository with a stub in place of vendor/bin/qa, which
 * records what it saw (environment, arguments, whether the scoped override existed) and can leave a stray .yml.
 *
 * @internal
 */
#[CoversNothing]
final class QaScopedScriptTest extends TestCase
{
    private const array SCRIPTS = ['check-qa-measurements.bash', 'mutation-scope.bash', 'mutation-scope.php', 'qa-scoped.bash'];

    private const string BASE = 'HEAD~1';

    private const string SCRIPTS_DIR = '/scripts/';

    private const string STUB_FILE = '/stub-qa';

    private const string OVERRIDE_FILE = '/qaConfig/infection.json';

    private const string STRAY_FILE = '/stray.yml';

    private const string COMMIT = 'commit';

    private const string STUB = <<<'BASH'
        #!/usr/bin/env bash
        {
            echo "CI=${CI:-}"
            echo "SKIP=${PHPXQ_INFECTION_SKIP:-}"
            echo "ARGS=$*"
            if [[ -f qaConfig/infection.json ]]; then echo "OVERRIDE=present"; else echo "OVERRIDE=absent"; fi
        } >var/stub.log
        touch stray.yml
        exit "${STUB_EXIT:-0}"
        BASH;

    private string $root;

    protected function setUp(): void
    {
        $project    = \dirname(__DIR__, 3);
        $this->root = sys_get_temp_dir() . '/phpxq-qa-scoped-' . bin2hex(random_bytes(6));
        foreach (['scripts', 'src', 'qaConfig', 'var/qa/phpunit_logs', 'var/qa/infection'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o755, true);
        }

        foreach (self::SCRIPTS as $script) {
            copy($project . self::SCRIPTS_DIR . $script, $this->root . self::SCRIPTS_DIR . $script);
            chmod($this->root . self::SCRIPTS_DIR . $script, 0o755);
        }

        file_put_contents($this->root . self::STUB_FILE, self::STUB . "\n");
        chmod($this->root . self::STUB_FILE, 0o755);
        symlink($project . '/vendor', $this->root . '/vendor');
        file_put_contents($this->root . '/.gitignore', "/vendor\n/var/\n/stub-qa\n/qaConfig/infection.json\n");
        file_put_contents($this->root . '/src/A.php', "<?php\n");
        file_put_contents($this->root . '/src/B.php', "<?php\n");
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git(self::COMMIT, '-q', '-m', 'base');
        file_put_contents($this->root . '/src/A.php', "<?php\n\n// changed\n");
        $this->git(self::COMMIT, '-q', '-am', 'change');

        file_put_contents(
            $this->root . '/var/qa/phpunit_logs/coverage.clover',
            '<metrics files="2" statements="100" coveredstatements="95" methods="10" coveredmethods="9"/>' . "\n",
        );
        touch($this->root . '/var/qa/phpunit_logs/coverage.clover', time() - 60);
        file_put_contents(
            $this->root . '/var/qa/infection/summary-log.txt',
            "Total: 0\nKilled by Test Framework: 0\nSkipped: 0\nIgnored: 0\nNot Covered: 0\n",
        );
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

    public function testAScopedRunGivesThePipelineTheOverrideAndRemovesItAndAStrayYmlAfterwards(): void
    {
        $run = $this->runScript([self::BASE, '--', '--extra-flag']);

        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        $log = $this->read('var/stub.log');
        self::assertStringContainsString("CI=true\n", $log);
        self::assertStringContainsString("SKIP=\n", $log);
        self::assertStringContainsString("ARGS=--extra-flag\n", $log);
        self::assertStringContainsString("OVERRIDE=present\n", $log);
        self::assertFileDoesNotExist($this->root . self::OVERRIDE_FILE);
        self::assertFileDoesNotExist($this->root . self::STRAY_FILE);
    }

    public function testABaseFromTheEnvironmentIsUsedWhenNoArgumentIsGiven(): void
    {
        $run = $this->runScript([], ['PHPXQ_MUTATION_BASE' => self::BASE]);

        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        self::assertStringContainsString('mutation base HEAD~1', $run->getOutput());
    }

    public function testAChangeThatMapsToNoSourceSwitchesInfectionOffAndWritesNoOverride(): void
    {
        file_put_contents($this->root . '/README.md', "docs\n");
        $this->git('add', 'README.md');
        $this->git(self::COMMIT, '-q', '-m', 'docs');

        $run = $this->runScript([self::BASE]);

        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        $log = $this->read('var/stub.log');
        self::assertStringContainsString("SKIP=1\n", $log);
        self::assertStringContainsString("OVERRIDE=absent\n", $log);
    }

    public function testAMissingBaseRefFailsClearlyWithoutRunningThePipeline(): void
    {
        $run = $this->runScript(['no-such-ref']);

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString("base ref 'no-such-ref' does not exist", $run->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/var/stub.log');
    }

    public function testAFailingPipelineFailsTheRunSkipsTheCheckAndStillCleansUp(): void
    {
        $run = $this->runScript([self::BASE], ['STUB_EXIT' => '3']);

        self::assertSame(3, $run->getExitCode());
        self::assertStringNotContainsString('no mutants', $run->getOutput());
        self::assertFileDoesNotExist($this->root . self::OVERRIDE_FILE);
        self::assertFileDoesNotExist($this->root . self::STRAY_FILE);
    }

    public function testAFailingMeasurementCheckFailsTheRun(): void
    {
        unlink($this->root . '/var/qa/infection/summary-log.txt');

        $run = $this->runScript([self::BASE]);

        self::assertNotSame(0, $run->getExitCode());
        self::assertFileDoesNotExist($this->root . self::OVERRIDE_FILE);
    }

    public function testAYmlAlreadyInTheRootIsLeftAlone(): void
    {
        file_put_contents($this->root . '/mine.yml', "a: 1\n");

        $run = $this->runScript([self::BASE]);

        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        self::assertFileExists($this->root . '/mine.yml');
        self::assertFileDoesNotExist($this->root . self::STRAY_FILE);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function runScript(array $arguments, array $environment = []): Process
    {
        return $this->execute(['scripts/qa-scoped.bash', ...$arguments], ['PHPXQ_QA_BIN' => $this->root . self::STUB_FILE, ...$environment]);
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
        $process = $this->execute(['git', '-c', 'user.name=test', '-c', 'user.email=test@example.invalid', ...array_values($arguments)]);
        if (0 !== $process->getExitCode()) {
            throw new RuntimeException('git failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    private function execute(array $command, array $environment = []): Process
    {
        $process = new Process($command, $this->root, ['GITHUB_OUTPUT' => '', 'PHPXQ_MUTATION_BASE' => '', ...$environment]);
        $process->run();

        return $process;
    }
}
