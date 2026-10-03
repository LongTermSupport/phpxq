<?php

declare(strict_types=1);

namespace LTS\PhpXq\Jq\Cli;

use RuntimeException;

/**
 * Whole-file reads that fail with jq's wording ("Could not open f: No such file or directory").
 *
 * @api
 */
final class FileReader
{
    private function __construct()
    {
    }

    /**
     * @throws RuntimeException the message is `Could not open <path>: <reason>`
     */
    public static function read(string $path): string
    {
        if (is_dir($path)) {
            throw new RuntimeException('Could not open ' . $path . ': Is a directory');
        }

        if (!file_exists($path)) {
            throw new RuntimeException('Could not open ' . $path . ': No such file or directory');
        }

        if (!is_readable($path)) {
            throw new RuntimeException('Could not open ' . $path . ': Permission denied');
        }

        set_error_handler(static fn (): bool => true);

        try {
            $contents = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        if (false === $contents) {
            throw new RuntimeException('Could not open ' . $path . ': Input/output error');
        }

        return $contents;
    }
}
