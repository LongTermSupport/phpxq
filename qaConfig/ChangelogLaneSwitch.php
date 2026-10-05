<?php

declare(strict_types=1);

namespace QaConfig;

/**
 * Decides whether the changelog lane applies to the checkout it runs in.
 *
 * The lane measures a branch from its merge base with the default branch. On the default branch itself it
 * measures from the newest `<PHP line>.N.N` tag (`85.N.N`), and phpxq tags `vX.Y.Z`, so there is no such tag
 * and the lane could only fail there. Releases are cut from the changelog, not from a lane run on main, so
 * the lane is switched off on the default branch and runs everywhere else.
 */
final readonly class ChangelogLaneSwitch
{
    private const string BRANCH_REF_PREFIX = 'ref: refs/heads/';

    public function __construct(private string $defaultBranch)
    {
    }

    /** @param string $gitPath the `.git` entry of the checkout: a directory, or a file pointing at a worktree's git directory */
    public function appliesTo(string $gitPath): bool
    {
        return $this->defaultBranch !== $this->currentBranch($gitPath);
    }

    private function currentBranch(string $gitPath): ?string
    {
        $gitDir = $gitPath;
        if (is_file($gitPath)) {
            $pointer = file_get_contents($gitPath);
            if (false === $pointer || !str_starts_with($pointer, 'gitdir: ')) {
                return null;
            }

            $gitDir = trim(substr($pointer, \strlen('gitdir: ')));
        }

        $head = is_file($gitDir . '/HEAD') ? file_get_contents($gitDir . '/HEAD') : false;
        if (false === $head || !str_starts_with($head, self::BRANCH_REF_PREFIX)) {
            return null;
        }

        return trim(substr($head, \strlen(self::BRANCH_REF_PREFIX)));
    }
}
