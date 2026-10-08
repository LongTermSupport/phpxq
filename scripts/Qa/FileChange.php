<?php

declare(strict_types=1);

namespace LTS\PhpXq\Qa;

/**
 * One path a change touched, with its git status letter (A, M, D, R, C, T).
 */
final readonly class FileChange
{
    public const string DELETED = 'D';

    public function __construct(
        public string $status,
        public string $path,
    ) {
    }

    /**
     * Parses `git diff --name-status -M` output. A rename or copy yields its old path as a deletion and its new
     * path with the R or C status, so a test that was moved away still maps to the code it guarded.
     *
     * @return list<self>
     */
    public static function parseNameStatus(string $output): array
    {
        $changes = [];
        foreach (explode("\n", $output) as $line) {
            $fields = explode("\t", rtrim($line, "\r"));
            if (\count($fields) < 2 || '' === $fields[0]) {
                continue;
            }

            $status = $fields[0][0];
            if (3 === \count($fields)) {
                $changes[] = new self(self::DELETED, $fields[1]);
                $changes[] = new self($status, $fields[2]);

                continue;
            }

            $changes[] = new self($status, $fields[1]);
        }

        return $changes;
    }
}
