<?php

declare(strict_types=1);

namespace LTS\PhpXq\Tests\Unit\QaConfig;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use QaConfig\ChangelogLaneSwitch;
use SplFileInfo;

/**
 * @internal
 */
final class ChangelogLaneSwitchTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpxq-lane-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/real/.git', 0o777, true);
        mkdir($this->root . '/worktree-gitdir');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testTheLaneIsOffOnTheDefaultBranch(): void
    {
        file_put_contents($this->root . '/real/.git/HEAD', "ref: refs/heads/main\n");

        self::assertFalse(new ChangelogLaneSwitch('main')->appliesTo($this->root . '/real/.git'));
    }

    public function testTheLaneRunsOnAnyOtherBranch(): void
    {
        file_put_contents($this->root . '/real/.git/HEAD', "ref: refs/heads/feature/x\n");

        self::assertTrue(new ChangelogLaneSwitch('main')->appliesTo($this->root . '/real/.git'));
    }

    public function testTheLaneRunsOnADetachedHead(): void
    {
        file_put_contents($this->root . '/real/.git/HEAD', "0123456789abcdef0123456789abcdef01234567\n");

        self::assertTrue(new ChangelogLaneSwitch('main')->appliesTo($this->root . '/real/.git'));
    }

    public function testAWorktreeGitFilePointsAtItsGitDirectory(): void
    {
        file_put_contents($this->root . '/worktree-gitdir/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->root . '/dot-git-file', 'gitdir: ' . $this->root . "/worktree-gitdir\n");

        self::assertFalse(new ChangelogLaneSwitch('main')->appliesTo($this->root . '/dot-git-file'));
    }

    public function testAnUnreadableCheckoutDefaultsToRunningTheLane(): void
    {
        file_put_contents($this->root . '/not-a-pointer', "something else\n");

        self::assertTrue(new ChangelogLaneSwitch('main')->appliesTo($this->root . '/not-a-pointer'));
        self::assertTrue(new ChangelogLaneSwitch('main')->appliesTo($this->root . '/missing'));
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            unlink($path);

            return;
        }

        foreach (new FilesystemIterator($path) as $entry) {
            if ($entry instanceof SplFileInfo) {
                $this->remove($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
