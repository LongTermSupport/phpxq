<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\Release;

use FilesystemIterator;
use LTS\PhpXq\Release\ReleaseCommand;
use LTS\PhpXq\Tests\Support\Release\ChangelogFixture;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * @internal
 */
final class ReleaseCommandTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpxq-release-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (new FilesystemIterator($this->root) as $file) {
            if ($file instanceof SplFileInfo) {
                unlink($file->getPathname());
            }
        }

        rmdir($this->root);
    }

    public function testNextVersionPrintsOnlyTheVersion(): void
    {
        $this->project('0.1.0', ChangelogFixture::withUnreleased(['Fixed' => ['A bug.']]));
        $tags = $this->file('tags', "v0.0.1\nv0.1.0\n");

        $result = new ReleaseCommand($this->root)->run('next-version', '--tags-file=' . $tags);

        self::assertSame(ReleaseCommand::EXIT_OK, $result->exitCode);
        self::assertSame("0.1.1\n", $result->stdout);
    }

    public function testNextVersionOfAnUnreleasedVersionIsThatVersion(): void
    {
        $this->project('0.1.0', ChangelogFixture::withUnreleased(['Added' => ['A thing.']]));

        $result = new ReleaseCommand($this->root)->run('next-version');

        self::assertSame("0.1.0\n", $result->stdout);
    }

    public function testNextVersionPrintsNothingWhenThereIsNothingToRelease(): void
    {
        $this->project('0.1.0', ChangelogFixture::emptyUnreleased());

        $result = new ReleaseCommand($this->root)->run('next-version');

        self::assertSame(ReleaseCommand::EXIT_OK, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString('No release is due', $result->stderr);
    }

    public function testPrepareWritesTheChangelogAndVersion(): void
    {
        $this->project('0.1.0', ChangelogFixture::withUnreleased(['Changed — breaking' => ['A contract.']]));
        $tags = $this->file('tags', "v0.1.0\n");

        $result = new ReleaseCommand($this->root)->run('prepare', '--tags-file=' . $tags, '--date=2026-05-06');

        self::assertSame(ReleaseCommand::EXIT_OK, $result->exitCode);
        self::assertSame("0.2.0\n", $result->stdout);
        self::assertSame("0.2.0\n", file_get_contents($this->root . '/VERSION'));
        self::assertStringContainsString("## Unreleased\n\n## 0.2.0 — 2026-05-06\n\n### Changed — breaking\n\n- A contract.\n", (string)file_get_contents($this->root . '/CHANGELOG.md'));
    }

    public function testPrepareDoesNothingWhenThereIsNothingToRelease(): void
    {
        $this->project('0.1.0', ChangelogFixture::emptyUnreleased());

        $result = new ReleaseCommand($this->root)->run('prepare');

        self::assertSame(ReleaseCommand::EXIT_OK, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame("0.1.0\n", file_get_contents($this->root . '/VERSION'));
        self::assertSame(ChangelogFixture::emptyUnreleased(), file_get_contents($this->root . '/CHANGELOG.md'));
    }

    public function testNotesRenderTheReleasedSection(): void
    {
        $this->project('0.1.0', ChangelogFixture::emptyUnreleased());

        $result = new ReleaseCommand($this->root)->run('notes', '0.1.0');

        self::assertSame(ReleaseCommand::EXIT_OK, $result->exitCode);
        self::assertStringContainsString("Added\n-----\n\n- First entry.", $result->stdout);
    }

    public function testNotesOfAnUnknownVersionAreRefused(): void
    {
        $this->project('0.1.0', ChangelogFixture::emptyUnreleased());

        $result = new ReleaseCommand($this->root)->run('notes', '9.9.9');

        self::assertSame(ReleaseCommand::EXIT_REFUSED, $result->exitCode);
        self::assertSame('', $result->stdout);
    }

    public function testNotesNeedExactlyOneVersion(): void
    {
        $this->project('0.1.0', ChangelogFixture::emptyUnreleased());

        self::assertSame(ReleaseCommand::EXIT_USAGE, new ReleaseCommand($this->root)->run('notes')->exitCode);
    }

    public function testVerifyExitCodesDistinguishReleasableNotReleaseAndRefused(): void
    {
        $command = new ReleaseCommand($this->root);

        $this->project('0.1.0', ChangelogFixture::emptyUnreleased());
        self::assertSame(ReleaseCommand::EXIT_OK, $command->run('verify')->exitCode);

        $tags = $this->file('tags', "v0.1.0\n");
        self::assertSame(ReleaseCommand::EXIT_REFUSED, $command->run('verify', '--tags-file=' . $tags)->exitCode);

        $this->project('0.1.0', ChangelogFixture::withUnreleased(['Added' => ['A thing.']]));
        self::assertSame(ReleaseCommand::EXIT_NOT_A_RELEASE, $command->run('verify')->exitCode);
    }

    public function testReconcilePrintsTheMergedChangelog(): void
    {
        $released = $this->file('released.md', ChangelogFixture::emptyUnreleased());
        $main     = $this->file('main.md', ChangelogFixture::withUnreleased(['Fixed' => ['New fix.']]));

        $result = new ReleaseCommand($this->root)->run('reconcile', $released, $main);

        self::assertSame(ReleaseCommand::EXIT_OK, $result->exitCode);
        self::assertSame(ChangelogFixture::withUnreleased(['Fixed' => ['New fix.']]), $result->stdout);
    }

    public function testReconcileNeedsTwoFiles(): void
    {
        self::assertSame(ReleaseCommand::EXIT_USAGE, new ReleaseCommand($this->root)->run('reconcile', 'only-one')->exitCode);
    }

    public function testMissingFilesAreRefused(): void
    {
        self::assertSame(ReleaseCommand::EXIT_REFUSED, new ReleaseCommand($this->root)->run('next-version')->exitCode);
    }

    public function testAnUnreadableTagsFileIsRefused(): void
    {
        $this->project('0.1.0', ChangelogFixture::emptyUnreleased());

        $result = new ReleaseCommand($this->root)->run('next-version', '--tags-file=' . $this->root . '/missing');

        self::assertSame(ReleaseCommand::EXIT_REFUSED, $result->exitCode);
    }

    public function testUnknownAndMissingCommandsPrintTheUsage(): void
    {
        $command = new ReleaseCommand($this->root);

        self::assertSame(ReleaseCommand::EXIT_USAGE, $command->run()->exitCode);
        self::assertSame(ReleaseCommand::EXIT_USAGE, $command->run('bogus')->exitCode);
        self::assertStringContainsString('Usage: scripts/release.php', $command->run('bogus')->stderr);
    }

    private function project(string $version, string $changelog): void
    {
        $this->file('VERSION', $version . "\n");
        $this->file('CHANGELOG.md', $changelog);
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
