<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Qa;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * Drives the real scripts/check-qa-measurements.bash in a throwaway directory holding a coverage report, an
 * Infection summary and the output of a QA run. The `Infection:` lines are the ones php-qa-ci's lane prints.
 *
 * @internal
 */
#[CoversNothing]
final class CheckQaMeasurementsScriptTest extends TestCase
{
    private const string SCRIPT = 'scripts/check-qa-measurements.bash';

    private const string LOG = 'var/qa-pipeline.log';

    private const string SUMMARY = 'var/qa/infection/summary-log.txt';

    private const string CLOVER = 'var/qa/phpunit_logs/coverage.clover';

    private const string DIFF = "Infection: auto diff mode — branch 'feature/x' against origin/main (merge base 1a2b3c4).\n"
        . "Infection: diff mode — mutating 1 file(s): src/Foo.php\n";

    private const string FULL_PREFIX = 'Infection: full run — auto diff mode does not apply: ';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpxq-check-measurements-' . bin2hex(random_bytes(6));
        foreach (['scripts', 'var/qa/phpunit_logs', 'var/qa/infection'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o755, true);
        }

        copy(\dirname(__DIR__, 3) . '/' . self::SCRIPT, $this->root . '/' . self::SCRIPT);
        chmod($this->root . '/' . self::SCRIPT, 0o755);
        $this->clover(95, 9);
        $this->summary(100, 0);
        $this->log(self::DIFF);
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

            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->root);
    }

    public function testADiffRunWithMutantsPasses(): void
    {
        $check = $this->check();

        self::assertSame(0, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('mutation score: MSI', $check->getOutput());
    }

    public function testAnUnsetLogSkipsTheScopeCheckOnly(): void
    {
        $check = $this->check(log: null);

        self::assertSame(0, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('not checked', $check->getOutput());
    }

    public function testALogThatDoesNotExistFails(): void
    {
        $check = $this->check(log: 'var/missing.log');

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('does not exist', $check->getOutput());
    }

    public function testALogWithNoInfectionScopeLineFails(): void
    {
        $this->log("Running PHPStan\nInfection: Xdebug is not enabled — cannot generate coverage, so mutation testing cannot run. SKIPPING.\n");

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('no Infection scope line', $check->getOutput());
    }

    #[DataProvider('provideFullRunsThatAreAFaultOfTheClone')]
    public function testAFullRunForAnyReasonButTheTwoAcceptedOnesFails(string $line): void
    {
        $this->log($line . "\n");

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('mutated all of src/', $check->getOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideFullRunsThatAreAFaultOfTheClone(): iterable
    {
        yield 'forced full run' => ['Infection: full run — every source file is mutated (withInfectionFullRun() / infectionDiffBase=full).'];
        yield 'detached HEAD' => [self::FULL_PREFIX . 'HEAD is detached and this is not a pull request build (GITHUB_BASE_REF is unset), so there is no branch to diff.'];
        yield 'unknown default branch' => [self::FULL_PREFIX . 'the default branch cannot be told (refs/remotes/origin/HEAD is unset and `git ls-remote --symref origin HEAD` gave no answer; `git remote set-head origin --auto` fixes it).'];
        yield 'shallow clone' => [self::FULL_PREFIX . 'HEAD and origin/main share no merge base in this clone, so the history is incomplete (a shallow clone?); `git fetch --unshallow`, or `fetch-depth: 0` on actions/checkout, restores diff mode.'];
        yield 'branch missing' => [self::FULL_PREFIX . 'neither origin/main nor main is in this clone; `git fetch origin main` restores diff mode.'];
    }

    public function testAFullRunBecauseConfigurationChangedPasses(): void
    {
        $this->log(self::DIFF . 'Infection: full run — the change touches configuration every mutant depends on (composer.lock), which a diff run cannot judge.' . "\n");

        $check = $this->check();

        self::assertSame(0, $check->getExitCode(), $check->getOutput());
    }

    public function testAFullRunOnTheDefaultBranchPasses(): void
    {
        $this->log(self::FULL_PREFIX . "on the default branch 'main'.\n");

        $check = $this->check();

        self::assertSame(0, $check->getExitCode(), $check->getOutput());
    }

    public function testADiffRunWithNothingToMutateNeedsNoSummary(): void
    {
        unlink($this->root . '/' . self::SUMMARY);
        $this->log("Infection: auto diff mode — branch 'feature/x' against origin/main (merge base 1a2b3c4).\n"
            . "Infection: diff mode — no PHP source change, and no changed test named after a source file, against 'origin/main'; there are no new mutants to check. SKIPPING.\n");

        $check = $this->check();

        self::assertSame(0, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('none to check', $check->getOutput());
    }

    public function testAMissingSummaryFailsWhenSomethingWasToBeMutated(): void
    {
        unlink($this->root . '/' . self::SUMMARY);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('no Infection summary', $check->getOutput());
    }

    public function testASummaryOlderThanTheCoverageReportFails(): void
    {
        touch($this->root . '/' . self::SUMMARY, time() - 600);
        touch($this->root . '/' . self::CLOVER, time() - 60);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('older than the coverage report', $check->getOutput());
    }

    public function testZeroMutantsOnADiffRunPass(): void
    {
        $this->summary(0, 0);

        $check = $this->check();

        self::assertSame(0, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('none were generated', $check->getOutput());
    }

    public function testZeroMutantsOverAllOfSrcFail(): void
    {
        $this->log(self::FULL_PREFIX . "on the default branch 'main'.\n");
        $this->summary(0, 0);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('no mutants from all of src/', $check->getOutput());
    }

    public function testZeroMutantsWithAConfigurationChangeFullRunFail(): void
    {
        $this->log(self::DIFF . 'Infection: full run — the change touches configuration every mutant depends on (composer.lock), which a diff run cannot judge.' . "\n");
        $this->summary(0, 0);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
    }

    public function testZeroMutantsWithoutAScopeLogFail(): void
    {
        $this->summary(0, 0);

        $check = $this->check(log: null);

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
    }

    public function testMoreSkippedMutantsThanTheCapFail(): void
    {
        $this->summary(100, 21);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('skipped 21 of 100', $check->getOutput());
    }

    public function testSkippedMutantsAtTheCapPass(): void
    {
        $this->summary(100, 20);

        $check = $this->check();

        self::assertSame(0, $check->getExitCode(), $check->getOutput());
    }

    public function testStatementCoverageBelowTheFloorFails(): void
    {
        $this->clover(92, 9);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('statements coverage 92.00% is below the floor', $check->getOutput());
    }

    public function testMethodCoverageBelowTheFloorFails(): void
    {
        $this->clover(95, 8);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('methods coverage 80.00% is below the floor', $check->getOutput());
    }

    public function testAMissingCoverageReportFails(): void
    {
        unlink($this->root . '/' . self::CLOVER);

        $check = $this->check();

        self::assertSame(1, $check->getExitCode(), $check->getOutput());
        self::assertStringContainsString('no coverage report', $check->getOutput());
    }

    private function check(?string $log = self::LOG): Process
    {
        // false removes the variable, so a log named in the environment of whoever runs the suite cannot leak in.
        $process = new Process([$this->root . '/' . self::SCRIPT], $this->root, ['PHPXQ_QA_LOG' => null === $log ? false : $log]);
        $process->run();

        return $process;
    }

    private function log(string $contents): void
    {
        file_put_contents($this->root . '/' . self::LOG, $contents);
    }

    private function clover(int $coveredStatements, int $coveredMethods): void
    {
        file_put_contents(
            $this->root . '/' . self::CLOVER,
            \sprintf('<metrics files="2" statements="100" coveredstatements="%d" methods="10" coveredmethods="%d"/>' . "\n", $coveredStatements, $coveredMethods),
        );
        touch($this->root . '/' . self::CLOVER, time() - 60);
    }

    private function summary(int $total, int $skipped): void
    {
        file_put_contents(
            $this->root . '/' . self::SUMMARY,
            \sprintf("Total: %d\nKilled by Test Framework: %d\nSkipped: %d\nIgnored: 0\nNot Covered: 0\n", $total, $total - $skipped, $skipped),
        );
    }
}
