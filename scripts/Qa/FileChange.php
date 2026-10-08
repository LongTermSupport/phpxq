<?php

declare(strict_types=1);

namespace LTS\PhpXq\Qa;

/**
 * One path a change touched, with its git status letter (A, C, D, M, R, T, U, X, B).
 */
final readonly class FileChange
{
    /** The status of a removed path, also given to the old side of a rename or copy. */
    public const string DELETED = 'D';

    /** What terminates every field of -z output. */
    private const string RECORD_END = "\0";

    /** A status git reports with one path; group 1 is the letter. */
    private const string ONE_PATH_STATUS = '/^([ADMTUXB])\d*$/D';

    /** A rename or copy, reported with a similarity score and two paths; group 1 is the letter. */
    private const string TWO_PATH_STATUS = '/^([RC])\d*$/D';

    public function __construct(
        public string $status,
        public string $path,
    ) {
    }

    /**
     * Parses `git diff -z --name-status -M`: NUL-terminated fields, a status then one path, or two paths for a rename
     * or copy. Paths are taken verbatim; -z output is never quoted. A rename or copy yields its old path as a
     * deletion and its new path with the R or C status, so a test that was moved away still maps to the code it
     * guarded.
     *
     * @return ?list<self> null when the output is not in that format: the caller must then assume everything changed
     */
    public static function parseNameStatusZ(string $output): ?array
    {
        if ('' === $output) {
            return [];
        }

        if (!str_ends_with($output, self::RECORD_END)) {
            return null;
        }

        $fields  = explode(self::RECORD_END, substr($output, 0, -1));
        $changes = [];
        $index   = 0;
        while ($index < \count($fields)) {
            $status = $fields[$index];
            if (1 === preg_match(self::TWO_PATH_STATUS, $status, $match)) {
                $paths = \array_slice($fields, $index + 1, 2);
                if (2 !== \count($paths) || \in_array('', $paths, true)) {
                    return null;
                }

                $changes[] = new self(self::DELETED, $paths[0]);
                $changes[] = new self($match[1], $paths[1]);
                $index += 3;

                continue;
            }

            if (1 !== preg_match(self::ONE_PATH_STATUS, $status, $match)) {
                return null;
            }

            if (!isset($fields[$index + 1]) || '' === $fields[$index + 1]) {
                return null;
            }

            $changes[] = new self($match[1], $fields[$index + 1]);
            $index += 2;
        }

        return $changes;
    }
}
